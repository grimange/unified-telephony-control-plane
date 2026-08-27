<template>
  <section
    class="workspace"
    aria-labelledby="external-trunks-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="external-trunks-title">
          External Trunks
        </h2><p class="meta">
          Connections between UTCP and external telephony providers or PBXs.
        </p>
      </div>
      <UiButton
        v-if="can('telephony.external_connectivity.manage')"
        variant="primary"
        @click="showCreate = !showCreate"
      >
        Add External Trunk
      </UiButton>
      <UiButton
        variant="secondary"
        :loading="loading"
        loading-label="Refreshing"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>
    <UiAlert
      v-if="error"
      variant="error"
      title="External Connectivity request failed"
    >
      {{ error }}
    </UiAlert>
    <UiPanel
      v-if="showCreate"
      title="Add External Trunk"
      label="Configuration"
    >
      <form
        class="inline-form"
        @submit.prevent="create"
      >
        <UiFormField
          id="trunk-name"
          label="Name"
          required
        >
          <template #default="{ id, describedBy }">
            <UiTextInput
              :id="id"
              v-model="form.name"
              :aria-describedby="describedBy"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="trunk-slug"
          label="Slug"
          required
        >
          <template #default="{ id, describedBy }">
            <UiTextInput
              :id="id"
              v-model="form.slug"
              :aria-describedby="describedBy"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="trunk-description"
          label="Description"
        >
          <template #default="{ id, describedBy }">
            <UiTextInput
              :id="id"
              v-model="form.description"
              :aria-describedby="describedBy"
            />
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :loading="saving"
        >
          Create
        </UiButton>
      </form>
    </UiPanel>
    <UiLoadingState v-if="loading && trunks.length === 0" />
    <UiEmptyState
      v-else-if="!loading && trunks.length === 0"
      title="No External Trunks configured"
      message="Add a trunk to connect UTCP to an external telephony provider or PBX."
    />
    <div
      v-else
      class="resource-grid"
    >
      <UiPanel
        v-for="trunk in trunks"
        :key="trunk.id"
        :title="trunk.name"
        :label="trunk.slug"
        :description="trunk.description || undefined"
      >
        <div class="resource-facts">
          <span><strong>Connection</strong> {{ connectionMode(trunk) }}</span>
          <span><strong>Status</strong> <UiStatusBadge
            :label="trunkStatus(trunk)"
            :category="statusCategory(trunk)"
          /></span>
          <span><strong>Provider endpoint</strong> {{ trunk.endpoints[0]?.endpoint_uri || 'Not configured' }}</span>
          <span><strong>Directions</strong> {{ trunk.supported_directions.join(', ') || 'Not specified' }}</span>
          <span><strong>Endpoints</strong> {{ trunk.endpoints.length }}</span>
          <span><strong>Addresses</strong> {{ trunk.addresses.length }}</span>
        </div>
        <div class="resource-actions">
          <RouterLink
            class="ui-button ui-button--secondary"
            :to="`/external-connectivity/trunks/${trunk.id}`"
          >
            View details
          </RouterLink>
        </div>
      </UiPanel>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { identityApi, type ExternalTrunk } from '../api/platform'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'

const trunks = ref<ExternalTrunk[]>([]); const loading = ref(false); const saving = ref(false); const error = ref(''); const showCreate = ref(false)
const form = reactive({ name: '', slug: '', description: '' })
async function load(): Promise<void> { loading.value = true; error.value = ''; try { trunks.value = (await identityApi.externalTrunks()).external_trunks } catch (e) { error.value = apiErrorMessage(e) } finally { loading.value = false } }
async function create(): Promise<void> { saving.value = true; error.value = ''; try { await identityApi.createExternalTrunk({ ...form }); form.name = ''; form.slug = ''; form.description = ''; showCreate.value = false; await load() } catch (e) { error.value = apiErrorMessage(e) } finally { saving.value = false } }
function connectionMode(trunk: ExternalTrunk): string { const mode = trunk.endpoints[0]?.signaling_mode; return mode === 'outbound_registration' ? 'SIP Registration' : mode === 'static' ? 'Static SIP' : 'Not configured' }
function trunkStatus(trunk: ExternalTrunk): string { const observation = trunk.endpoints.find((e) => e.signaling_mode === 'outbound_registration')?.registration_observation; if (observation?.state === 'registered') return 'Registered'; if (observation?.state === 'failed') return 'Registration failed'; if (observation?.state === 'expired') return 'Registration expired'; if (trunk.desired_state === 'retired') return 'Retired'; if (trunk.desired_state === 'disabled') return 'Disabled'; if (trunk.desired_state === 'draft') return 'Draft'; return trunk.observed_health === 'ready' ? 'Active' : 'Unavailable' }
function statusCategory(trunk: ExternalTrunk): 'neutral' | 'success' | 'warning' | 'danger' { const status = trunkStatus(trunk); return status === 'Registered' || status === 'Active' ? 'success' : status.includes('failed') || status === 'Unavailable' ? 'danger' : status === 'Draft' ? 'neutral' : 'warning' }
onMounted(load); watch(tenantContextVersion, load)
</script>

<style scoped>
.resource-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); gap: 1rem; }
.resource-facts { display: grid; gap: .65rem; color: var(--color-text-muted); }
.resource-facts strong { color: var(--color-text); margin-right: .35rem; }
.resource-actions { margin-top: 1rem; }
</style>
