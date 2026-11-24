#!/bin/sh
# Teste do chart Helm em um cluster Kubernetes local (k3s em contêiner).
#
#   docker compose -f compose.prod.yaml build        # imagens fornevia-app e fornevia-web
#   sh scripts/k8s-smoke.sh [tag]                    # padrão: appVersion do chart
#
# Sobe o k3s, importa as imagens locais, cria PostgreSQL e Redis de teste
# (deploy/kubernetes/dependencias-teste.yaml), instala o chart e verifica:
# migrations pelo Job de pré-instalação, réplicas prontas, /health/ready,
# requisições sem erro durante a perda de um pod e durante uma atualização.
# KEEP_CLUSTER=1 mantém o cluster ao final para inspeção.
set -eu

CHART=deploy/helm/fornevia
TAG=${1:-$(sed -n 's/^appVersion: *"\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CHART/Chart.yaml")}
CLUSTER=fornevia-k3s
NS=fornevia
RELEASE=fornevia
WEB=${RELEASE}-fornevia-web
HOST=fornevia.local
K3S_IMAGE=rancher/k3s:v1.34.1-k3s1
HELM_IMAGE=alpine/helm:3.19.0

kubectl() { docker exec -i "$CLUSTER" kubectl -n "$NS" "$@"; }
helm() {
    docker run --rm --network "container:$CLUSTER" -v "$PWD:/apps" -w /apps \
        -v "$KUBECONFIG_DIR:/kube:ro" -e KUBECONFIG=/kube/config "$HELM_IMAGE" -n "$NS" "$@"
}
random() { head -c 32 /dev/urandom | base64 | tr -d '/+=' | cut -c1-32; }
# Sonda fora dos pods do chart: continua viva enquanto eles são substituídos.
ready() { kubectl exec prober -- wget -q -O /dev/null -T 5 --header "Host: $HOST" "http://$WEB/health/ready"; }
cleanup() {
    rm -rf "$KUBECONFIG_DIR"
    [ "${KEEP_CLUSTER:-0}" = 1 ] || docker rm -f "$CLUSTER" >/dev/null 2>&1 || true
}

KUBECONFIG_DIR=$(mktemp -d)
trap cleanup EXIT

echo "==> Cluster k3s"
docker rm -f "$CLUSTER" >/dev/null 2>&1 || true
# Cluster descartável: limites de despejo e de coleta de imagens baixos, para
# não depender da folga de disco do host nem perder as imagens importadas.
docker run -d --name "$CLUSTER" --privileged --tmpfs /run --tmpfs /var/run "$K3S_IMAGE" server --disable traefik \
    --kubelet-arg='eviction-hard=nodefs.available<1%,imagefs.available<1%' \
    --kubelet-arg=image-gc-high-threshold=99 --kubelet-arg=image-gc-low-threshold=98 >/dev/null
for _ in $(seq 1 60); do
    docker exec "$CLUSTER" kubectl get nodes 2>/dev/null | grep -q ' Ready' && break
    sleep 2
done
docker exec "$CLUSTER" cat /etc/rancher/k3s/k3s.yaml > "$KUBECONFIG_DIR/config"
chmod 0644 "$KUBECONFIG_DIR/config"

echo "==> Imagens ($TAG)"
# Sem a tag pedida, usa a última imagem construída pelo compose.prod.yaml.
for image in fornevia-app fornevia-web; do
    docker image inspect "$image:$TAG" >/dev/null 2>&1 || docker tag "$image:latest" "$image:$TAG"
done
POSTGRES_IMAGE=$(sed -n 's/^ *image: \(postgres:.*\)$/\1/p' deploy/kubernetes/dependencias-teste.yaml)
REDIS_IMAGE=$(sed -n 's/^ *image: \(redis:.*\)$/\1/p' deploy/kubernetes/dependencias-teste.yaml)
docker pull -q "$POSTGRES_IMAGE" >/dev/null
docker pull -q "$REDIS_IMAGE" >/dev/null
docker save "fornevia-app:$TAG" "fornevia-web:$TAG" "$POSTGRES_IMAGE" "$REDIS_IMAGE" \
    | docker exec -i "$CLUSTER" ctr -n k8s.io images import - >/dev/null

echo "==> Segredos e dependências de teste"
docker exec "$CLUSTER" kubectl create namespace "$NS" >/dev/null
kubectl create secret generic fornevia-secrets \
    --from-literal=APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
    --from-literal=DB_PASSWORD="$(random)" --from-literal=DB_OWNER_PASSWORD="$(random)" \
    --from-literal=DB_RELAY_PASSWORD="$(random)" --from-literal=REDIS_PASSWORD="$(random)" \
    --from-literal=METRICS_TOKEN="$(random)" >/dev/null
kubectl create secret generic fornevia-teste-db --from-literal=DB_SUPERUSER_PASSWORD="$(random)" >/dev/null
kubectl create configmap fornevia-teste-db-init --from-file=01-roles.sh=/dev/stdin < docker/postgres/init/01-roles.sh >/dev/null
kubectl apply -f - < deploy/kubernetes/dependencias-teste.yaml >/dev/null
kubectl rollout status statefulset/postgres --timeout=180s
kubectl rollout status deployment/redis --timeout=120s

echo "==> Instalação do chart"
helm install "$RELEASE" "$CHART" -f "$CHART/ci/local-values.yaml" --set image.app.tag="$TAG",image.web.tag="$TAG" \
    --wait --timeout 10m
kubectl get pods -o wide
kubectl run prober --image="$REDIS_IMAGE" --restart=Never --command -- sleep 3600 >/dev/null
kubectl wait --for=condition=Ready pod/prober --timeout=60s >/dev/null
ready
echo "health/ready: ok"

# Requisições contínuas ao Service (como o ingress faria) durante a perturbação.
probe_loop() {
    kubectl exec prober -- sh -c '
        ok=0; fail=0; end=$(( $(date +%s) + '"$1"' ))
        while [ "$(date +%s)" -lt "$end" ]; do
            if wget -q -O /dev/null -T 5 --header "Host: '"$HOST"'" http://'"$WEB"'/health/ready; then ok=$((ok+1)); else fail=$((fail+1)); fi
            sleep 0.2
        done
        echo "$ok $fail"'
}
check_loop() {
    set -- $1
    echo "requisições: $1 com sucesso, $2 com falha"
    [ "$2" -eq 0 ] || { echo "Falhas durante a perturbação." >&2; exit 1; }
}

echo "==> Perda de um pod da aplicação"
probe_loop 40 > "$KUBECONFIG_DIR/loss" &
LOOP=$!
sleep 5
POD=$(kubectl get pods -l app.kubernetes.io/component=app -o jsonpath='{.items[0].metadata.name}')
kubectl delete pod "$POD" --wait=false >/dev/null
wait "$LOOP"
check_loop "$(cat "$KUBECONFIG_DIR/loss")"

echo "==> Atualização sem indisponibilidade (revisão 2)"
probe_loop 150 > "$KUBECONFIG_DIR/upgrade" &
LOOP=$!
sleep 3
helm upgrade "$RELEASE" "$CHART" -f "$CHART/ci/local-values.yaml" --set image.app.tag="$TAG",image.web.tag="$TAG" \
    --set-string 'podAnnotations.fornevia/revisao=2' --wait --timeout 10m
wait "$LOOP"
check_loop "$(cat "$KUBECONFIG_DIR/upgrade")"
helm history "$RELEASE"
kubectl get pods
echo "Chart verificado no cluster local."
