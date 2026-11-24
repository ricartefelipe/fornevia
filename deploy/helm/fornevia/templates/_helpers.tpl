{{- define "fornevia.name" -}}{{ .Chart.Name }}{{- end -}}

{{- define "fornevia.fullname" -}}{{ printf "%s-%s" .Release.Name .Chart.Name | trunc 50 | trimSuffix "-" }}{{- end -}}

{{- define "fornevia.labels" -}}
app.kubernetes.io/name: {{ include "fornevia.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version }}
{{- end -}}

{{- define "fornevia.selector" -}}
app.kubernetes.io/name: {{ include "fornevia.name" .root }}
app.kubernetes.io/instance: {{ .root.Release.Name }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "fornevia.appImage" -}}{{ .Values.image.app.repository }}:{{ .Values.image.app.tag | default .Chart.AppVersion }}{{- end -}}
{{- define "fornevia.webImage" -}}{{ .Values.image.web.repository }}:{{ .Values.image.web.tag | default .Chart.AppVersion }}{{- end -}}

{{/* Contexto de segurança dos processos PHP: sem root, sem escalonamento, raiz somente leitura. */}}
{{- define "fornevia.phpSecurityContext" -}}
securityContext:
  runAsNonRoot: true
  runAsUser: 82
  runAsGroup: 82
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true
  capabilities:
    drop: [ALL]
  seccompProfile:
    type: RuntimeDefault
{{- end -}}

{{/* Variáveis, diretórios graváveis e volume compartilhado dos processos PHP. */}}
{{- define "fornevia.phpEnv" -}}
envFrom:
  - configMapRef:
      name: {{ include "fornevia.fullname" . }}-config
  - secretRef:
      name: {{ .Values.existingSecret }}
volumeMounts:
  - {name: tmp, mountPath: /tmp}
  - {name: bootstrap-cache, mountPath: /var/www/html/bootstrap/cache}
  - {name: framework, mountPath: /var/www/html/storage/framework}
  - {name: logs, mountPath: /var/www/html/storage/logs}
  {{- if .Values.persistence.enabled }}
  - {name: storage-app, mountPath: /var/www/html/storage/app}
  {{- end }}
{{- end -}}

{{- define "fornevia.phpVolumes" -}}
volumes:
  - {name: tmp, emptyDir: {}}
  - {name: bootstrap-cache, emptyDir: {}}
  - {name: framework, emptyDir: {}}
  - {name: logs, emptyDir: {}}
  {{- if .Values.persistence.enabled }}
  - name: storage-app
    persistentVolumeClaim:
      claimName: {{ .Values.persistence.existingClaim | default (printf "%s-storage" (include "fornevia.fullname" .)) }}
  {{- end }}
{{- end -}}

{{- define "fornevia.podCommon" -}}
{{- with .root.Values.imagePullSecrets }}
imagePullSecrets: {{ toYaml . | nindent 2 }}
{{- end }}
securityContext:
  fsGroup: 82
{{- with .root.Values.nodeSelector }}
nodeSelector: {{ toYaml . | nindent 2 }}
{{- end }}
{{- with .root.Values.tolerations }}
tolerations: {{ toYaml . | nindent 2 }}
{{- end }}
{{- if .root.Values.topologySpreadConstraints }}
topologySpreadConstraints:
  - maxSkew: 1
    topologyKey: kubernetes.io/hostname
    whenUnsatisfiable: ScheduleAnyway
    labelSelector:
      matchLabels: {{- include "fornevia.selector" . | nindent 8 }}
{{- end }}
{{- end -}}
