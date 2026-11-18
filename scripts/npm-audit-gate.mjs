#!/usr/bin/env node
/**
 * Gate da auditoria de dependências npm.
 *
 * Lê o relatório `npm audit --json` e falha quando houver alerta de
 * severidade moderada ou maior que não esteja em
 * .github/npm-audit-exceptions.json. Cada exceção exige justificativa e
 * data de revisão: exceção vencida ou que não corresponde mais a nenhum
 * alerta também falha, para que a lista não envelheça.
 *
 * Uso: npm audit --json > build/npm-audit.json || true
 *      node scripts/npm-audit-gate.mjs build/npm-audit.json [AAAA-MM-DD]
 */
import { readFileSync } from 'node:fs';

const SEVERITIES = ['moderate', 'high', 'critical'];
const [reportPath, todayArg] = process.argv.slice(2);

if (!reportPath) {
    console.error('Informe o relatório de npm audit --json.');
    process.exit(2);
}

const report = JSON.parse(readFileSync(reportPath, 'utf8'));

if (report.error || typeof report.vulnerabilities !== 'object') {
    console.error('Relatório de auditoria inválido ou com erro:', JSON.stringify(report.error ?? report).slice(0, 500));
    process.exit(1);
}

const { exceptions } = JSON.parse(readFileSync(new URL('../.github/npm-audit-exceptions.json', import.meta.url), 'utf8'));
const today = todayArg ?? new Date().toISOString().slice(0, 10);
const failures = [];

for (const exception of exceptions) {
    for (const field of ['advisory', 'package', 'reason', 'reviewBy']) {
        if (typeof exception[field] !== 'string' || exception[field].trim() === '') {
            failures.push(`Exceção sem o campo "${field}": ${JSON.stringify(exception)}`);
        }
    }

    if (typeof exception.reviewBy === 'string' && exception.reviewBy < today) {
        failures.push(`Exceção ${exception.advisory} (${exception.package}) venceu em ${exception.reviewBy}: revise ou remova.`);
    }
}

const advisories = new Map();

for (const [name, vulnerability] of Object.entries(report.vulnerabilities)) {
    for (const via of vulnerability.via ?? []) {
        if (typeof via !== 'object' || !SEVERITIES.includes(via.severity)) {
            continue;
        }

        const id = String(via.url ?? '').split('/').pop() || String(via.source);
        advisories.set(`${id}|${via.name ?? name}`, { id, package: via.name ?? name, severity: via.severity, title: via.title });
    }
}

const excepted = new Set(exceptions.map((e) => `${e.advisory}|${e.package}`));

for (const advisory of advisories.values()) {
    const key = `${advisory.id}|${advisory.package}`;

    if (excepted.has(key)) {
        console.log(`Exceção aplicada: ${advisory.id} (${advisory.package}, ${advisory.severity}) — ${advisory.title}`);
    } else {
        failures.push(`Alerta sem exceção: ${advisory.id} (${advisory.package}, ${advisory.severity}) — ${advisory.title}`);
    }
}

for (const exception of exceptions) {
    if (!advisories.has(`${exception.advisory}|${exception.package}`)) {
        failures.push(`Exceção ${exception.advisory} (${exception.package}) não corresponde a nenhum alerta atual: remova-a.`);
    }
}

if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}

console.log(`Auditoria npm aprovada: ${advisories.size} alerta(s), todos com exceção registrada.`);
