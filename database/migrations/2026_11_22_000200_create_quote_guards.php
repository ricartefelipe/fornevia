<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Proteções das cotações no banco (ADR-025), além dos privilégios por
 * coluna e dos CHECKs:
 *
 * - quote_guard: número definitivo atribuído uma vez; transições da
 *   cotação conforme o mapa (QuoteLifecycle); situações terminais fixas;
 * - quote_version_guard: versão nasce em preparo e sem reserva, numa
 *   cotação aberta; transições conforme o mapa; número, envio e aceite
 *   gravados uma vez; reserva só de "sem" para "ativa" no envio e de
 *   "ativa" para "encerrada" uma vez; envio só com avaliação comercial sem
 *   violação ou aprovada (RN-041);
 * - quote_items_reconcile (diferido): contagem e somas dos itens iguais aos
 *   totais da versão no COMMIT; como os totais são imutáveis, nenhum item
 *   entra depois da transação que criou a versão;
 * - quote_review_guard: avaliação só de versão em preparo; decisão gravada
 *   uma vez, sobre versão em aprovação (ou na submissão, na autoaprovação).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function quote_guard() returns trigger
                language plpgsql
                as $$
                declare
                    allowed text[];
                begin
                    if new.number is distinct from old.number
                        and (old.number not like 'PEND-%' or new.number like 'PEND-%') then
                        raise exception 'cotação %: o número definitivo é atribuído uma única vez', old.id
                            using errcode = 'check_violation';
                    end if;

                    if new.status is distinct from old.status then
                        allowed := case old.status
                            when 'requested' then array['proposed', 'cancelled']
                            when 'proposed' then array['requested', 'expired', 'accepted', 'rejected', 'cancelled']
                            when 'expired' then array['proposed', 'requested', 'cancelled']
                            else array[]::text[]
                        end;

                        if not (new.status = any (allowed)) then
                            raise exception 'cotação %: transição % para % não permitida', old.id, old.status, new.status
                                using errcode = 'check_violation';
                        end if;
                    end if;

                    if old.status in ('accepted', 'rejected', 'cancelled') and (
                        new.closed_at is distinct from old.closed_at or new.closed_by_user_id is distinct from old.closed_by_user_id
                        or new.close_reason_code is distinct from old.close_reason_code or new.close_reason is distinct from old.close_reason) then
                        raise exception 'cotação % encerrada', old.id
                            using errcode = 'check_violation';
                    end if;

                    return new;
                end
                $$;

            create trigger quotes_guard before update on quotes
                for each row execute function quote_guard();

            create or replace function quote_version_guard() returns trigger
                language plpgsql
                as $$
                declare
                    allowed text[];
                    parent_status text;
                    review_ok boolean;
                begin
                    if tg_op = 'INSERT' then
                        if new.status not in ('pending_approval', 'ready') or new.stock_hold_status <> 'none' then
                            raise exception 'versão de cotação nasce em preparo e sem reserva'
                                using errcode = 'check_violation';
                        end if;

                        select q.status into parent_status from quotes q where q.id = new.quote_id and q.tenant_id = new.tenant_id;

                        if parent_status is null or parent_status in ('accepted', 'rejected', 'cancelled') then
                            raise exception 'cotação % encerrada: não recebe nova versão', new.quote_id
                                using errcode = 'check_violation';
                        end if;

                        return new;
                    end if;

                    if new.status is distinct from old.status then
                        allowed := case old.status
                            when 'pending_approval' then array['ready', 'approval_rejected', 'withdrawn', 'expired']
                            when 'ready' then array['sent', 'withdrawn', 'expired']
                            when 'sent' then array['accepted', 'superseded', 'countered', 'rejected', 'expired', 'withdrawn']
                            else array[]::text[]
                        end;

                        if not (new.status = any (allowed)) then
                            raise exception 'versão %: transição % para % não permitida', old.id, old.status, new.status
                                using errcode = 'check_violation';
                        end if;
                    end if;

                    if (old.version_number is not null and new.version_number is distinct from old.version_number)
                        or (old.sent_at is not null and new.sent_at is distinct from old.sent_at)
                        or (old.sent_by_membership_id is not null and new.sent_by_membership_id is distinct from old.sent_by_membership_id)
                        or (old.accepted_at is not null and (new.accepted_at is distinct from old.accepted_at
                            or new.accepted_by_user_id is distinct from old.accepted_by_user_id
                            or new.accepted_by_membership_id is distinct from old.accepted_by_membership_id))
                        or (old.closed_at is not null and new.closed_at is distinct from old.closed_at) then
                        raise exception 'versão %: envio, número, aceite e encerramento são gravados uma única vez', old.id
                            using errcode = 'check_violation';
                    end if;

                    if new.stock_hold_status is distinct from old.stock_hold_status then
                        if not ((old.stock_hold_status = 'none' and new.stock_hold_status = 'active' and old.status = 'ready' and new.status = 'sent')
                            or (old.stock_hold_status = 'active' and new.stock_hold_status = 'ended')) then
                            raise exception 'versão %: reserva % para % não permitida', old.id, old.stock_hold_status, new.stock_hold_status
                                using errcode = 'check_violation';
                        end if;
                    elsif old.stock_hold_status = 'ended' and (new.stock_hold_ended_at is distinct from old.stock_hold_ended_at
                        or new.stock_hold_end_reason is distinct from old.stock_hold_end_reason) then
                        raise exception 'versão %: fim da reserva gravado uma única vez', old.id
                            using errcode = 'check_violation';
                    end if;

                    -- RN-041: proposta com violação da política só vai ao
                    -- comprador aprovada.
                    if new.status = 'sent' and old.status = 'ready' then
                        select (not r.requires_approval) or r.decision = 'approved' into review_ok
                          from quote_commercial_reviews r
                         where r.quote_version_id = new.id and r.tenant_id = new.tenant_id;

                        if review_ok is not true then
                            raise exception 'versão %: envio exige avaliação comercial sem violação ou aprovada', old.id
                                using errcode = 'check_violation';
                        end if;
                    end if;

                    return new;
                end
                $$;

            create trigger quote_versions_guard before insert or update on quote_versions
                for each row execute function quote_version_guard();

            create or replace function quote_items_reconcile() returns trigger
                language plpgsql
                as $$
                declare
                    expected record;
                    actual record;
                begin
                    select v.item_count, v.subtotal_cents, v.total_cents into expected
                      from quote_versions v where v.id = new.quote_version_id and v.tenant_id = new.tenant_id;

                    select count(*) as item_count, coalesce(sum(i.line_gross_cents), 0) as subtotal_cents,
                           coalesce(sum(i.line_total_cents), 0) as total_cents
                      into actual
                      from quote_items i where i.quote_version_id = new.quote_version_id and i.tenant_id = new.tenant_id;

                    if expected.item_count is distinct from actual.item_count::smallint
                        or expected.subtotal_cents is distinct from actual.subtotal_cents::bigint
                        or expected.total_cents is distinct from actual.total_cents::bigint then
                        raise exception 'versão %: itens não conferem com os totais', new.quote_version_id
                            using errcode = 'check_violation';
                    end if;

                    return null;
                end
                $$;

            create constraint trigger quote_items_reconcile after insert on quote_items
                deferrable initially deferred
                for each row execute function quote_items_reconcile();

            create or replace function quote_review_guard() returns trigger
                language plpgsql
                as $$
                declare
                    version_status text;
                begin
                    select v.status into version_status from quote_versions v where v.id = new.quote_version_id and v.tenant_id = new.tenant_id;

                    if tg_op = 'INSERT' then
                        if version_status not in ('pending_approval', 'ready') then
                            raise exception 'avaliação comercial só de versão em preparo'
                                using errcode = 'check_violation';
                        end if;

                        return new;
                    end if;

                    if old.decision is not null and (new.decision is distinct from old.decision
                        or new.decided_by_user_id is distinct from old.decided_by_user_id
                        or new.decided_by_membership_id is distinct from old.decided_by_membership_id
                        or new.decider_limit_bps is distinct from old.decider_limit_bps
                        or new.decision_comment is distinct from old.decision_comment
                        or new.decided_at is distinct from old.decided_at
                        or new.self_approved is distinct from old.self_approved) then
                        raise exception 'avaliação comercial %: decisão gravada uma única vez', old.id
                            using errcode = 'check_violation';
                    end if;

                    if old.decision is null and new.decision is not null and version_status is distinct from 'pending_approval' then
                        raise exception 'avaliação comercial %: só versão em aprovação recebe decisão', old.id
                            using errcode = 'check_violation';
                    end if;

                    return new;
                end
                $$;

            create trigger quote_commercial_reviews_guard before insert or update on quote_commercial_reviews
                for each row execute function quote_review_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists quote_commercial_reviews_guard on quote_commercial_reviews;
            drop function if exists quote_review_guard();
            drop trigger if exists quote_items_reconcile on quote_items;
            drop function if exists quote_items_reconcile();
            drop trigger if exists quote_versions_guard on quote_versions;
            drop function if exists quote_version_guard();
            drop trigger if exists quotes_guard on quotes;
            drop function if exists quote_guard();
            SQL);
    }
};
