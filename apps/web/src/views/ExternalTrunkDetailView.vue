<template>
  <section
    class="workspace"
    aria-labelledby="trunk-detail-title"
  >
    <p>
      <RouterLink to="/external-connectivity/trunks">
        ← External Trunks
      </RouterLink>
    </p>
    <UiLoadingState v-if="loading" />
    <UiAlert
      v-else-if="error"
      variant="error"
      title="External Connectivity request failed"
    >
      {{ error }}
    </UiAlert>
    <template v-else-if="trunk">
      <div class="section-heading">
        <div>
          <h2 id="trunk-detail-title">
            {{ trunk.name }}
          </h2><p class="meta">
            {{ trunk.description || 'External provider connection' }}
          </p>
        </div><UiStatusBadge
          :label="status"
          :category="category"
        />
      </div>
      <UiPanel
        title="Connection"
        label="External connectivity"
      >
        <dl class="facts">
          <dt>Mode</dt><dd>{{ mode }}</dd><dt>Directions</dt><dd>{{ trunk.supported_directions.join(', ') || 'Not specified' }}</dd><dt>Observed health</dt><dd>{{ trunk.observed_health }}</dd>
        </dl><UiButton
          v-if="canManage && trunk.desired_state !== 'retired'"
          variant="secondary"
          @click="changeState(trunk.desired_state === 'active' ? 'disabled' : 'active')"
        >
          {{ trunk.desired_state === 'active' ? 'Disable' : 'Activate' }}
        </UiButton>
      </UiPanel>
      <UiPanel
        title="Provider Endpoints"
        label="Connection details"
      >
        <UiEmptyState
          v-if="trunk.endpoints.length === 0"
          title="No Provider Endpoints"
          message="Add an endpoint to describe how this trunk connects."
        /><div
          v-for="endpoint in trunk.endpoints"
          :key="endpoint.id"
          class="endpoint"
        >
          <strong>{{ endpoint.endpoint_uri }}</strong><span>{{ endpoint.signaling_mode === 'outbound_registration' ? 'SIP Registration' : 'Static SIP' }} · {{ endpoint.transport }} · {{ endpoint.authentication_mode }}</span><span v-if="endpoint.signaling_mode === 'outbound_registration'">Registration: {{ endpoint.registration_observation?.state || 'Not observed' }}</span><span v-if="endpoint.registration_observation?.last_success_at">Last successful: {{ endpoint.registration_observation.last_success_at }}</span><UiButton
            v-if="canManage && endpoint.desired_state !== 'retired'"
            variant="ghost"
            @click="changeEndpointState(endpoint.id, endpoint.desired_state === 'active' ? 'disabled' : 'active')"
          >
            {{ endpoint.desired_state === 'active' ? 'Deactivate' : 'Activate' }}
          </UiButton>
        </div><form
          v-if="canManage"
          class="inline-form"
          @submit.prevent="addEndpoint"
        >
          <UiFormField
            id="endpoint-uri"
            label="Endpoint URI"
            required
          >
            <template #default="{ id, describedBy }">
              <UiTextInput
                :id="id"
                v-model="endpointForm.endpoint_uri"
                :aria-describedby="describedBy"
                placeholder="sip:provider.example"
                required
              />
            </template>
          </UiFormField><UiFormField
            id="endpoint-mode"
            label="Connection mode"
          >
            <template #default="{ id, describedBy }">
              <UiSelect
                :id="id"
                v-model="endpointForm.signaling_mode"
                :aria-describedby="describedBy"
              >
                <option value="static">
                  Static SIP
                </option><option value="outbound_registration">
                  SIP Registration
                </option>
              </UiSelect>
            </template>
          </UiFormField><template v-if="endpointForm.signaling_mode === 'outbound_registration'">
            <UiFormField
              id="endpoint-credential"
              label="Credential reference"
              required
            >
              <template #default="{ id, describedBy }">
                <UiSelect
                  :id="id"
                  v-model="endpointForm.credential_reference_id"
                  :aria-describedby="describedBy"
                  required
                >
                  <option
                    value=""
                    disabled
                  >
                    Select a credential
                  </option><option
                    v-for="credential in trunk.credential_references"
                    :key="credential.id"
                    :value="credential.id"
                  >
                    {{ credential.credential_type }} · {{ credential.identifier || credential.id }}
                  </option>
                </UiSelect>
              </template>
            </UiFormField><UiFormField
              id="registration-target"
              label="Registration target"
              required
            >
              <template #default="{ id, describedBy }">
                <UiTextInput
                  :id="id"
                  v-model="endpointForm.registration_target"
                  :aria-describedby="describedBy"
                  required
                />
              </template>
            </UiFormField><UiFormField
              id="registration-realm"
              label="Registration realm"
              required
            >
              <template #default="{ id, describedBy }">
                <UiTextInput
                  :id="id"
                  v-model="endpointForm.registration_realm"
                  :aria-describedby="describedBy"
                  required
                />
              </template>
            </UiFormField><UiFormField
              id="registration-identity"
              label="Registration identity"
              required
            >
              <template #default="{ id, describedBy }">
                <UiTextInput
                  :id="id"
                  v-model="endpointForm.registration_identity"
                  :aria-describedby="describedBy"
                  required
                />
              </template>
            </UiFormField>
          </template><UiButton type="submit">
            Add Endpoint
          </UiButton>
        </form>
      </UiPanel>
      <UiPanel
        title="Credentials"
        label="Safe reference metadata"
      >
        <div
          v-for="credential in trunk.credential_references"
          :key="credential.id"
          class="endpoint"
        >
          <span>{{ credential.credential_type }} · {{ credential.identifier || 'No identifier' }} · version {{ credential.version }} · {{ credential.status }}</span>
        </div><form
          v-if="canManage"
          class="inline-form"
          @submit.prevent="addCredential"
        >
          <UiFormField
            id="credential-type"
            label="Type"
            required
          >
            <template #default="{ id, describedBy }">
              <UiTextInput
                :id="id"
                v-model="credentialForm.credential_type"
                :aria-describedby="describedBy"
                required
              />
            </template>
          </UiFormField><UiFormField
            id="credential-identifier"
            label="Identifier"
          >
            <template #default="{ id, describedBy }">
              <UiTextInput
                :id="id"
                v-model="credentialForm.identifier"
                :aria-describedby="describedBy"
              />
            </template>
          </UiFormField><UiFormField
            id="credential-secret"
            label="Secret"
            required
          >
            <template #default="{ id, describedBy }">
              <UiTextInput
                :id="id"
                v-model="credentialForm.secret"
                type="password"
                :aria-describedby="describedBy"
                autocomplete="new-password"
                required
              />
            </template>
          </UiFormField><UiButton type="submit">
            Save credential
          </UiButton>
        </form>
      </UiPanel>
      <UiPanel
        title="Numbers & Addresses"
        label="Associated addresses"
      >
        <p
          v-if="trunk.addresses.length === 0"
          class="meta"
        >
          No addresses are associated with this trunk.
        </p><ul>
          <li
            v-for="address in trunk.addresses"
            :key="address.id"
          >
            {{ address.value }} · {{ address.direction }} · {{ address.desired_state }}
          </li>
        </ul><form
          v-if="canManage && addresses.length"
          class="inline-form"
          @submit.prevent="attachAddress"
        >
          <UiSelect
            id="address-choice"
            v-model="addressForm.id"
          >
            <option
              v-for="address in addresses"
              :key="address.id"
              :value="address.id"
            >
              {{ address.value }}
            </option>
          </UiSelect><UiSelect
            id="address-direction"
            v-model="addressForm.direction"
          >
            <option value="inbound">
              Inbound
            </option><option value="outbound">
              Outbound
            </option><option value="both">
              Both
            </option>
          </UiSelect><UiButton type="submit">
            Associate address
          </UiButton>
        </form>
      </UiPanel>
    </template>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'; import { useRoute } from 'vue-router'; import { identityApi, type ExternalTrunk, type TelephonyAddress } from '../api/platform'; import UiAlert from '../components/ui/UiAlert.vue'; import UiButton from '../components/ui/UiButton.vue'; import UiEmptyState from '../components/ui/UiEmptyState.vue'; import UiFormField from '../components/ui/UiFormField.vue'; import UiLoadingState from '../components/ui/UiLoadingState.vue'; import UiPanel from '../components/ui/UiPanel.vue'; import UiSelect from '../components/ui/UiSelect.vue'; import UiStatusBadge from '../components/ui/UiStatusBadge.vue'; import UiTextInput from '../components/ui/UiTextInput.vue'; import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'
const route = useRoute(); const trunk = ref<ExternalTrunk | null>(null); const addresses = ref<TelephonyAddress[]>([]); const loading = ref(true); const error = ref(''); const canManage = can('telephony.external_connectivity.manage'); const endpointForm = reactive({ endpoint_uri: '', signaling_mode: 'static', credential_reference_id: '', registration_target: '', registration_realm: '', registration_identity: '' }); const credentialForm = reactive({ credential_type: 'sip', identifier: '', secret: '' }); const addressForm = reactive({ id: '', direction: 'both' })
const mode = computed(() => trunk.value?.endpoints[0]?.signaling_mode === 'outbound_registration' ? 'SIP Registration' : 'Static SIP'); const status = computed(() => { const t = trunk.value; const o = t?.endpoints.find((e) => e.signaling_mode === 'outbound_registration')?.registration_observation; if (o?.state === 'registered') return 'Registered'; if (o?.state === 'failed') return 'Registration failed'; if (o?.state === 'expired') return 'Registration expired'; if (t?.desired_state === 'retired') return 'Retired'; if (t?.desired_state === 'disabled') return 'Disabled'; return t?.observed_health === 'ready' ? 'Active' : 'Unavailable' }); const category = computed(() => status.value === 'Registered' || status.value === 'Active' ? 'success' : status.value.includes('failed') || status.value === 'Unavailable' ? 'danger' : 'warning')
async function load() { loading.value = true; error.value = ''; try { trunk.value = (await identityApi.externalTrunk(String(route.params.id))).external_trunk; addresses.value = (await identityApi.telephonyAddresses()).telephony_addresses; if (!addressForm.id) addressForm.id = addresses.value[0]?.id || '' } catch (e) { error.value = apiErrorMessage(e) } finally { loading.value = false } }
async function changeState(state: string) { try { trunk.value = (await identityApi.setExternalTrunkState(trunk.value!.id, state)).external_trunk } catch (e) { error.value = apiErrorMessage(e) } }
async function changeEndpointState(id: string, state: string) { try { await identityApi.setExternalTrunkEndpointState(trunk.value!.id, id, state); await load() } catch (e) { error.value = apiErrorMessage(e) } }
async function addEndpoint() { try { await identityApi.createExternalTrunkEndpoint(trunk.value!.id, { endpoint_uri: endpointForm.endpoint_uri, signaling_mode: endpointForm.signaling_mode, transport: 'udp', authentication_mode: endpointForm.signaling_mode === 'outbound_registration' ? 'credentials' : 'none', ...(endpointForm.signaling_mode === 'outbound_registration' ? { credential_reference_id: endpointForm.credential_reference_id, registration_target: endpointForm.registration_target, registration_realm: endpointForm.registration_realm, registration_identity: endpointForm.registration_identity } : {}) }); Object.assign(endpointForm, { endpoint_uri: '', credential_reference_id: '', registration_target: '', registration_realm: '', registration_identity: '' }); await load() } catch (e) { error.value = apiErrorMessage(e) } }
async function addCredential() { const secret = credentialForm.secret; try { await identityApi.createExternalTrunkCredential(trunk.value!.id, { credential_type: credentialForm.credential_type, identifier: credentialForm.identifier, secret }); credentialForm.secret = ''; await load() } catch (e) { error.value = apiErrorMessage(e) } }
async function attachAddress() { try { await identityApi.attachExternalTrunkAddress(trunk.value!.id, { telephony_address_id: addressForm.id, direction: addressForm.direction }); await load() } catch (e) { error.value = apiErrorMessage(e) } }
onMounted(load); watch(tenantContextVersion, load)
</script>
<style scoped>.facts { display:grid; grid-template-columns:max-content 1fr; gap:.6rem 1.5rem; margin:0 0 1rem; }.facts dt { color:var(--color-text-muted); }.facts dd { margin:0; }.endpoint { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; padding:.8rem 0; border-bottom:1px solid var(--color-border); }.endpoint:last-child { border-bottom:0; }</style>
