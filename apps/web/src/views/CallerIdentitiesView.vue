<template>
  <section
    class="workspace"
    aria-labelledby="caller-identities-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="caller-identities-title">
          Caller Identities
        </h2><p class="meta">
          Calling identities that may be authorized for External Trunks.
        </p>
      </div><UiButton
        variant="secondary"
        :loading="loading"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>
    <UiAlert
      v-if="error"
      variant="error"
      title="Caller Identity request failed"
    >
      {{ error }}
    </UiAlert>
    <UiPanel
      v-if="canManage"
      title="Add Caller Identity"
      label="Canonical identity"
    >
      <form
        class="identity-form"
        @submit.prevent="create"
      >
        <UiFormField
          id="identity-name"
          label="Name"
          required
        >
          <template #default="{ id }">
            <UiTextInput
              :id="id"
              v-model="form.name"
              required
            />
          </template>
        </UiFormField><UiFormField
          id="identity-address"
          label="Telephony Address"
          required
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              v-model="form.telephony_address_id"
              required
            >
              <option value="">
                Select an active address
              </option><option
                v-for="address in activeAddresses"
                :key="address.id"
                :value="address.id"
              >
                {{ address.value }}
              </option>
            </UiSelect>
          </template>
        </UiFormField><UiFormField
          id="identity-display-name"
          label="Display Name"
        >
          <template #default="{ id }">
            <UiTextInput
              :id="id"
              v-model="form.display_name"
            />
          </template>
        </UiFormField><UiButton
          type="submit"
          :loading="saving"
        >
          Add Caller Identity
        </UiButton>
      </form>
    </UiPanel>
    <UiLoadingState v-if="loading && identities.length===0" /><UiEmptyState
      v-else-if="!loading && identities.length===0"
      title="No Caller Identities configured"
      message="Caller Identities define the calling identity UTCP may use on authorized External Trunks."
    />
    <UiPanel
      v-else
      title="Configured Caller Identities"
      label="Canonical lifecycle"
    >
      <div class="identity-list">
        <article
          v-for="identity in identities"
          :key="identity.id"
          class="identity-row"
        >
          <div>
            <strong>{{ identity.name }}</strong><p class="meta">
              {{ identity.display_name || 'No display name' }} · {{ identity.telephony_address?.value || `Address unavailable (${identity.telephony_address_id.slice(0, 8)})` }}
            </p>
          </div><UiStatusBadge
            :label="stateLabel(identity.desired_state)"
            :category="stateCategory(identity.desired_state)"
          /><div>
            <strong>Authorized External Trunks</strong><p class="meta">
              {{ policyLabel(identity) }}
            </p>
          </div><div
            v-if="canManage"
            class="identity-actions"
          >
            <UiButton
              v-if="identity.desired_state === 'draft' || identity.desired_state === 'disabled'"
              variant="ghost"
              @click="changeState(identity.id,'active')"
            >
              Activate
            </UiButton><UiButton
              v-if="identity.desired_state === 'active'"
              variant="ghost"
              @click="changeState(identity.id,'disabled')"
            >
              Disable
            </UiButton><UiButton
              v-if="identity.desired_state !== 'retired'"
              variant="ghost"
              @click="changeState(identity.id,'retired')"
            >
              Retire
            </UiButton>
          </div><div
            v-if="canManage && identity.desired_state !== 'retired'"
            class="policy-form"
          >
            <UiSelect
              v-model="policyTrunks[identity.id]"
              aria-label="External Trunk for caller identity"
            >
              <option value="">
                Authorize an External Trunk
              </option><option
                v-for="trunk in trunks"
                :key="trunk.id"
                :value="trunk.id"
              >
                {{ trunk.name }}
              </option>
            </UiSelect><UiButton
              variant="secondary"
              :disabled="!policyTrunks[identity.id]"
              @click="addPolicy(identity.id)"
            >
              Authorize
            </UiButton>
          </div>
        </article>
      </div>
    </UiPanel>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'; import { identityApi, type CallerIdentity, type ExternalTrunk, type TelephonyAddress } from '../api/platform'; import UiAlert from '../components/ui/UiAlert.vue'; import UiButton from '../components/ui/UiButton.vue'; import UiEmptyState from '../components/ui/UiEmptyState.vue'; import UiFormField from '../components/ui/UiFormField.vue'; import UiLoadingState from '../components/ui/UiLoadingState.vue'; import UiPanel from '../components/ui/UiPanel.vue'; import UiSelect from '../components/ui/UiSelect.vue'; import UiStatusBadge from '../components/ui/UiStatusBadge.vue'; import UiTextInput from '../components/ui/UiTextInput.vue'; import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'
const identities=ref<CallerIdentity[]>([]);const addresses=ref<TelephonyAddress[]>([]);const trunks=ref<ExternalTrunk[]>([]);const loading=ref(false);const saving=ref(false);const error=ref('');const canManage=can('telephony.external_connectivity.manage');const form=reactive({name:'',telephony_address_id:'',display_name:''});const policyTrunks=reactive<Record<string,string>>({});const activeAddresses=computed(()=>addresses.value.filter(a=>a.desired_state==='active'))
async function load(){loading.value=true;error.value='';identities.value=[];try{const results=await Promise.allSettled([identityApi.callerIdentities(),identityApi.telephonyAddresses(),identityApi.externalTrunks()]);if(results[0].status==='fulfilled')identities.value=results[0].value.caller_identities;else error.value=apiErrorMessage(results[0].reason);if(results[1].status==='fulfilled')addresses.value=results[1].value.telephony_addresses;if(results[2].status==='fulfilled')trunks.value=results[2].value.external_trunks}catch(e){error.value=apiErrorMessage(e)}finally{loading.value=false}}
async function create(){saving.value=true;error.value='';try{await identityApi.createCallerIdentity({name:form.name,telephony_address_id:form.telephony_address_id,display_name:form.display_name||null});Object.assign(form,{name:'',telephony_address_id:'',display_name:''});await load()}catch(e){error.value=apiErrorMessage(e)}finally{saving.value=false}}
async function changeState(id:string,state:string){try{await identityApi.setCallerIdentityState(id,state);await load()}catch(e){error.value=apiErrorMessage(e)}}async function addPolicy(id:string){const trunk=policyTrunks[id];if(!trunk)return;try{await identityApi.createCallerIdentityPolicy(id,trunk);policyTrunks[id]='';await load()}catch(e){error.value=apiErrorMessage(e)}}function policyLabel(identity:CallerIdentity){return identity.policies.length?identity.policies.filter(p=>p.desired_state==='active').map(p=>p.external_trunk_name).join(', ')||'No active policies':'No External Trunks authorized'}function stateLabel(state:string){return state.charAt(0).toUpperCase()+state.slice(1)}function stateCategory(state:string){return state==='active'?'success':state==='draft'?'neutral':state==='retired'?'danger':'warning'}watch(tenantContextVersion,load);onMounted(load)
</script>
<style scoped>.identity-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;align-items:end}.identity-list{display:grid}.identity-row{display:grid;grid-template-columns:minmax(12rem,1fr) auto minmax(12rem,1fr) auto;gap:1rem;align-items:center;padding:1rem 0;border-bottom:1px solid var(--color-border)}.identity-row:last-child{border-bottom:0}.identity-row p{margin:.35rem 0 0}.policy-form{grid-column:1/-1;display:flex;gap:.5rem;align-items:center}@media(max-width:700px){.identity-row{grid-template-columns:1fr}.policy-form{grid-column:auto;flex-wrap:wrap}}</style>
