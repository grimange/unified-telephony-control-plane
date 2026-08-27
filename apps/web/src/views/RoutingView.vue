<template>
  <section
    class="workspace"
    aria-labelledby="routing-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="routing-title">
          Routes
        </h2><p class="meta">
          Configure how canonical addresses connect providers and destinations.
        </p>
      </div><UiButton
        variant="secondary"
        :loading="loading"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>
    <nav
      class="segmented"
      aria-label="Route direction"
    >
      <UiButton
        type="button"
        :variant="direction === 'inbound' ? 'primary' : 'secondary'"
        @click="direction = 'inbound'"
      >
        Inbound
      </UiButton><UiButton
        type="button"
        :variant="direction === 'outbound' ? 'primary' : 'secondary'"
        @click="direction = 'outbound'"
      >
        Outbound
      </UiButton>
    </nav>
    <UiAlert
      v-if="error"
      variant="error"
      title="Routing request failed"
    >
      {{ error }}
    </UiAlert>
    <UiPanel
      v-if="canManage"
      title="Add Route"
      label="Canonical routing intent"
    >
      <p class="meta">
        Inbound matches an External Trunk and called address to a destination. Outbound connects a destination address to a trunk and optional caller identity.
      </p><form
        class="route-form"
        @submit.prevent="create"
      >
        <UiFormField
          id="route-name"
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
        </UiFormField>
        <UiFormField
          id="route-slug"
          label="Slug"
          required
        >
          <template #default="{ id }">
            <UiTextInput
              :id="id"
              v-model="form.slug"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="route-trunk"
          label="External Trunk"
          required
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              v-model="form.external_trunk_id"
              required
            >
              <option value="">
                Select a trunk
              </option><option
                v-for="trunk in usableTrunks"
                :key="trunk.id"
                :value="trunk.id"
              >
                {{ trunk.name }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="route-address"
          :label="direction === 'inbound' ? 'Called Number / Address' : 'Destination Number / Address'"
          required
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              v-model="form.telephony_address_id"
              required
            >
              <option value="">
                Select an address
              </option><option
                v-for="address in usableAddresses"
                :key="address.id"
                :value="address.id"
              >
                {{ address.value }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          v-if="direction === 'inbound'"
          id="route-destination"
          label="Destination"
          required
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              v-model="form.destination_ref"
              required
            >
              <option value="">
                Select a destination address
              </option><option
                v-for="address in addresses"
                :key="address.id"
                :value="`telephony_address:${address.id}`"
              >
                {{ address.value }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          v-else
          id="route-caller"
          label="Default Caller Identity"
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              v-model="form.caller_identity_id"
            >
              <option value="">
                No default caller identity
              </option><option
                v-for="identity in usableIdentities"
                :key="identity.id"
                :value="identity.id"
              >
                {{ identity.name }} — {{ identity.telephony_address.value }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="route-priority"
          label="Priority"
          help="Lower numbers are considered first."
        >
          <template #default="{ id }">
            <UiTextInput
              :id="id"
              v-model.number="form.priority"
              type="number"
              min="1"
              max="100000"
            />
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :loading="saving"
        >
          Add {{ direction }} route
        </UiButton>
      </form>
    </UiPanel>
    <UiLoadingState v-if="loading && routes.length === 0" /><UiEmptyState
      v-else-if="!loading && routes.length === 0"
      title="No routes configured"
      message="Routes connect canonical Numbers & Addresses to External Trunks and call destinations."
    />
    <UiPanel
      v-else
      title="Configured routes"
      label="Canonical lifecycle"
    >
      <div class="route-list">
        <article
          v-for="route in routes"
          :key="route.id"
          class="route-row"
        >
          <div>
            <strong>{{ route.name }}</strong><p class="meta">
              {{ directionLabel(route) }} · Priority {{ route.priority }} · {{ addressLabel(route.telephony_address_id) }}
            </p>
          </div><div class="route-facts">
            <span>{{ trunkLabel(route.external_trunk_id) }}</span><span v-if="direction === 'inbound'">{{ destinationLabel(route.destination_ref) }}</span><span v-else>{{ callerLabel(route.caller_identity_id) }}</span><UiStatusBadge
              :label="stateLabel(route.desired_state)"
              :category="stateCategory(route.desired_state)"
            />
          </div><div
            v-if="canManage && route.desired_state !== 'retired'"
            class="route-actions"
          >
            <UiButton
              v-if="route.desired_state === 'draft' || route.desired_state === 'disabled'"
              variant="ghost"
              @click="changeState(route.id, 'active')"
            >
              Activate
            </UiButton><UiButton
              v-if="route.desired_state === 'active'"
              variant="ghost"
              @click="changeState(route.id, 'disabled')"
            >
              Disable
            </UiButton><UiButton
              v-if="route.desired_state !== 'retired'"
              variant="ghost"
              @click="changeState(route.id, 'retired')"
            >
              Retire
            </UiButton>
          </div>
        </article>
      </div>
    </UiPanel>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { identityApi, type CallerIdentity, type ExternalTrunk, type InboundRoute, type OutboundRoute, type TelephonyAddress, type RouteDestinationRef } from '../api/platform'
import UiAlert from '../components/ui/UiAlert.vue'; import UiButton from '../components/ui/UiButton.vue'; import UiEmptyState from '../components/ui/UiEmptyState.vue'; import UiFormField from '../components/ui/UiFormField.vue'; import UiLoadingState from '../components/ui/UiLoadingState.vue'; import UiPanel from '../components/ui/UiPanel.vue'; import UiSelect from '../components/ui/UiSelect.vue'; import UiStatusBadge from '../components/ui/UiStatusBadge.vue'; import UiTextInput from '../components/ui/UiTextInput.vue'; import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'
const direction=ref<'inbound'|'outbound'>('inbound'); const routes=ref<Array<InboundRoute|OutboundRoute>>([]); const trunks=ref<ExternalTrunk[]>([]); const addresses=ref<TelephonyAddress[]>([]); const identities=ref<CallerIdentity[]>([]); const loading=ref(false); const saving=ref(false); const error=ref(''); const canManage=can('telephony.routing.manage')
const form=reactive({name:'',slug:'',external_trunk_id:'',telephony_address_id:'',destination_ref:'',caller_identity_id:'',priority:100})
const usableTrunks=computed(()=>trunks.value.filter(t=>t.desired_state!=='retired' && t.supported_directions.includes(direction.value))); const selectedTrunk=computed(()=>trunks.value.find(t=>t.id===form.external_trunk_id)); const usableAddresses=computed(()=>addresses.value.filter(a=>a.desired_state==='active' && (!selectedTrunk.value || selectedTrunk.value.addresses.some(association=>association.id===a.id && (association.direction===direction.value || association.direction==='both'))))); const usableIdentities=computed(()=>identities.value.filter(i=>i.desired_state==='active' && (!form.external_trunk_id || i.policies.some(p=>p.external_trunk_id===form.external_trunk_id && p.desired_state==='active'))))
async function load(){loading.value=true;error.value='';routes.value=[];try{const [resource, trunkResult, addressResult, identityResult]=await Promise.allSettled([direction.value==='inbound'?identityApi.inboundRoutes():identityApi.outboundRoutes(),identityApi.externalTrunks(),identityApi.telephonyAddresses(),identityApi.callerIdentities()]);if(resource.status==='fulfilled'){routes.value=direction.value==='inbound'&&'inbound_routes' in resource.value?resource.value.inbound_routes:'outbound_routes' in resource.value?resource.value.outbound_routes:[]}else error.value=apiErrorMessage(resource.reason);if(trunkResult.status==='fulfilled')trunks.value=trunkResult.value.external_trunks;if(addressResult.status==='fulfilled')addresses.value=addressResult.value.telephony_addresses;if(identityResult.status==='fulfilled')identities.value=identityResult.value.caller_identities}catch(e){error.value=apiErrorMessage(e)}finally{loading.value=false}}
async function create(){saving.value=true;error.value='';try{const payload={name:form.name,slug:form.slug,external_trunk_id:form.external_trunk_id,telephony_address_id:form.telephony_address_id,priority:form.priority,...(direction.value==='inbound'?{destination_ref:form.destination_ref}:{caller_identity_id:form.caller_identity_id||null})};if(direction.value==='inbound')await identityApi.createInboundRoute(payload);else await identityApi.createOutboundRoute(payload);Object.assign(form,{name:'',slug:'',external_trunk_id:'',telephony_address_id:'',destination_ref:'',caller_identity_id:'',priority:100});await load()}catch(e){error.value=apiErrorMessage(e)}finally{saving.value=false}}
async function changeState(id:string,state:string){try{if(direction.value==='inbound')await identityApi.setInboundRouteState(id,state);else await identityApi.setOutboundRouteState(id,state);await load()}catch(e){error.value=apiErrorMessage(e)}}
function addressLabel(id:string){return addresses.value.find(a=>a.id===id)?.value??`Address unavailable (${id.slice(0,8)})`} function trunkLabel(id:string){return trunks.value.find(t=>t.id===id)?.name??`External Trunk unavailable (${id.slice(0,8)})`} function callerLabel(id:string|null){return id?identities.value.find(i=>i.id===id)?.name??`Caller Identity unavailable (${id.slice(0,8)})`:'Caller identity selected at call time'} function destinationLabel(ref:RouteDestinationRef|null){if(!ref)return 'No destination';return ref.type==='telephony_address'?addressLabel(ref.value):`Advanced destination: ${ref.value}`} function directionLabel(route:InboundRoute|OutboundRoute){return route.direction==='inbound'?'Inbound':'Outbound'} function stateLabel(state:string){return state.charAt(0).toUpperCase()+state.slice(1)} function stateCategory(state:string){return state==='active'?'success':state==='draft'?'neutral':state==='retired'?'danger':'warning'}
watch(direction,load); watch(tenantContextVersion,load); onMounted(load)
</script>
<style scoped>.segmented{display:flex;gap:.5rem;margin-bottom:1rem}.route-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem;align-items:end}.route-list{display:grid;gap:0}.route-row{display:grid;grid-template-columns:minmax(12rem,1.2fr) minmax(12rem,1fr) auto;gap:1rem;align-items:center;padding:1rem 0;border-bottom:1px solid var(--color-border)}.route-row:last-child{border-bottom:0}.route-row p{margin:.35rem 0 0}.route-facts{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;color:var(--color-text-muted)}.route-actions{display:flex;gap:.35rem;justify-content:flex-end}@media(max-width:700px){.route-row{grid-template-columns:1fr}.route-actions{justify-content:flex-start}}</style>
