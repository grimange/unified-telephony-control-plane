package active

import (
	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	"k8s.io/apimachinery/pkg/api/resource"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/util/intstr"
	lifecycle "utcp.invalid/components/kubernetes-effects/lifecycle"
)

const AsteriskProfile = "asterisk.managed.v1"
const FreeSWITCHProfile = "freeswitch.managed.v1"

func Ownership(a Action) map[string]string {
	return lifecycle.Ownership(lifecycle.Action{Target: a.Target, TenantID: a.TenantID, RuntimeNodeID: a.RuntimeNodeID})
}

// DesiredDeployment is a closed projection, not a manifest parser. The only
// configurable template values are digest identity and a scoped Secret slot.
// Secret material is never read. Namespace/name/selector identity use the
// one-way lifecycle incarnation rather than the legacy PHP physical name.
func DesiredDeployment(a Action) *appsv1.Deployment {
	p := a.Intent.Projection
	f, t := false, true
	grace := int64(30)
	family, role := "asterisk", "asterisk-ari"
	if p.TemplateVersion == FreeSWITCHProfile {
		family, role = "freeswitch", "freeswitch-esl"
	}
	labels := Ownership(a)
	labels["app.kubernetes.io/component"] = role
	podLabels := Ownership(a)
	podLabels["app.kubernetes.io/component"] = role
	podLabels["utcp.io/network-role"] = role
	healthCommand := []string{"/usr/local/bin/utcp-asterisk-readiness"}
	period, fail := int32(15), int32(3)
	if family == "freeswitch" {
		healthCommand = []string{"/usr/local/bin/utcp-freeswitch-healthcheck"}
		period, fail = 10, 6
	}
	probe := &corev1.Probe{ProbeHandler: corev1.ProbeHandler{Exec: &corev1.ExecAction{Command: healthCommand}}, PeriodSeconds: period, TimeoutSeconds: 5, FailureThreshold: fail}
	c := corev1.Container{Name: family, Image: p.Image, ImagePullPolicy: corev1.PullAlways, EnvFrom: []corev1.EnvFromSource{{SecretRef: &corev1.SecretEnvSource{LocalObjectReference: corev1.LocalObjectReference{Name: p.SecretName}}}}, Resources: corev1.ResourceRequirements{Requests: corev1.ResourceList{corev1.ResourceCPU: resource.MustParse("50m"), corev1.ResourceMemory: resource.MustParse("128Mi")}, Limits: corev1.ResourceList{corev1.ResourceCPU: resource.MustParse("500m"), corev1.ResourceMemory: resource.MustParse("384Mi")}}, SecurityContext: &corev1.SecurityContext{AllowPrivilegeEscalation: &f, Capabilities: &corev1.Capabilities{Drop: []corev1.Capability{"ALL"}}}, StartupProbe: probe.DeepCopy(), ReadinessProbe: probe.DeepCopy(), LivenessProbe: probe.DeepCopy()}
	pod := corev1.PodSpec{AutomountServiceAccountToken: &f, TerminationGracePeriodSeconds: &grace, SecurityContext: &corev1.PodSecurityContext{RunAsNonRoot: &t, SeccompProfile: &corev1.SeccompProfile{Type: corev1.SeccompProfileTypeRuntimeDefault}}}
	if family == "asterisk" {
		c.Ports = []corev1.ContainerPort{{Name: "ari", ContainerPort: 8088, Protocol: corev1.ProtocolTCP}, {Name: "sip", ContainerPort: 5060, Protocol: corev1.ProtocolUDP}}
		c.VolumeMounts = []corev1.VolumeMount{{Name: "asterisk-local-config", MountPath: "/opt/utcp-asterisk-local-config", ReadOnly: true}}
		c.StartupProbe.InitialDelaySeconds = 15
		c.StartupProbe.FailureThreshold = 12
		c.LivenessProbe = &corev1.Probe{ProbeHandler: corev1.ProbeHandler{Exec: &corev1.ExecAction{Command: []string{"/usr/sbin/asterisk", "-C", "/tmp/utcp-asterisk/asterisk.conf", "-rx", "core show uptime"}}}, InitialDelaySeconds: 20, PeriodSeconds: 20, TimeoutSeconds: 5, FailureThreshold: 3}
		pod.Volumes = []corev1.Volume{{Name: "asterisk-local-config", VolumeSource: corev1.VolumeSource{ConfigMap: &corev1.ConfigMapVolumeSource{LocalObjectReference: corev1.LocalObjectReference{Name: "asterisk-local-sip-fixtures"}, Optional: &t}}}}
	} else {
		u := int64(1000)
		pod.SecurityContext.RunAsUser = &u
		pod.SecurityContext.RunAsGroup = &u
		pod.SecurityContext.FSGroup = &u
		c.SecurityContext.ReadOnlyRootFilesystem = &t
		c.StartupProbe.FailureThreshold = 24
		c.Ports = []corev1.ContainerPort{{Name: "sip", ContainerPort: 5060, Protocol: corev1.ProtocolUDP}, {Name: "esl", ContainerPort: 8021, Protocol: corev1.ProtocolTCP}, {Name: "rtp", ContainerPort: 21000, Protocol: corev1.ProtocolUDP}}
		for _, v := range [][2]string{{"freeswitch-runtime", "/var/lib/freeswitch"}, {"freeswitch-run", "/var/run/freeswitch"}, {"freeswitch-log", "/var/log/freeswitch"}} {
			c.VolumeMounts = append(c.VolumeMounts, corev1.VolumeMount{Name: v[0], MountPath: v[1]})
			pod.Volumes = append(pod.Volumes, corev1.Volume{Name: v[0], VolumeSource: corev1.VolumeSource{EmptyDir: &corev1.EmptyDirVolumeSource{}}})
		}
	}
	pod.Containers = []corev1.Container{c}
	quarter := intstr.FromString("25%")
	return &appsv1.Deployment{ObjectMeta: metav1.ObjectMeta{Name: a.Target.Name, Namespace: a.Target.Namespace, Labels: labels}, Spec: appsv1.DeploymentSpec{Replicas: a.Intent.Replicas, Paused: false, Selector: &metav1.LabelSelector{MatchLabels: map[string]string{Prefix + "incarnation-id": a.Target.IncarnationID}}, Strategy: appsv1.DeploymentStrategy{Type: appsv1.RollingUpdateDeploymentStrategyType, RollingUpdate: &appsv1.RollingUpdateDeployment{MaxSurge: &quarter, MaxUnavailable: &quarter}}, Template: corev1.PodTemplateSpec{ObjectMeta: metav1.ObjectMeta{Labels: podLabels}, Spec: pod}}}
}
