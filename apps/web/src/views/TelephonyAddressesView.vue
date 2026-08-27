<template>
  <section
    class="workspace"
    aria-labelledby="addresses-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="addresses-title">
          Numbers & Addresses
        </h2><p class="meta">
          Telephony addresses available for provider and route configuration.
        </p>
      </div><UiButton
        variant="secondary"
        :loading="loading"
        @click="load"
      >
        Refresh
      </UiButton>
    </div><UiAlert
      v-if="error"
      variant="error"
      title="Numbers & Addresses request failed"
    >
      {{ error }}
    </UiAlert><UiPanel
      v-if="canManage"
      title="Add Address"
      label="Canonical address"
    >
      <form
        class="inline-form"
        @submit.prevent="create"
      >
        <UiFormField
          id="address-type"
          label="Address type"
          required
        >
          <template #default="{ id, describedBy }">
            <UiTextInput
              :id="id"
              v-model="form.address_type"
              :aria-describedby="describedBy"
              required
            />
          </template>
        </UiFormField><UiFormField
          id="address-value"
          label="Value"
          required
        >
          <template #default="{ id, describedBy }">
            <UiTextInput
              :id="id"
              v-model="form.value"
              :aria-describedby="describedBy"
              required
            />
          </template>
        </UiFormField><UiButton type="submit">
          Add Address
        </UiButton>
      </form>
    </UiPanel><UiLoadingState v-if="loading && addresses.length === 0" /><UiEmptyState
      v-else-if="!loading && addresses.length === 0"
      title="No Numbers or Addresses configured"
      message="Add an address to make it available for External Connectivity configuration."
    /><UiPanel
      v-else
      title="Configured addresses"
      label="Canonical state"
    >
      <div
        v-for="address in addresses"
        :key="address.id"
        class="address-row"
      >
        <strong>{{ address.value }}</strong><span>{{ address.type }} · {{ address.desired_state }}</span><UiButton
          v-if="canManage && address.desired_state !== 'active' && address.desired_state !== 'retired'"
          variant="ghost"
          @click="activate(address.id)"
        >
          Activate
        </UiButton>
      </div>
    </UiPanel>
  </section>
</template>
<script setup lang="ts">import { onMounted, reactive, ref, watch } from 'vue'; import { identityApi, type TelephonyAddress } from '../api/platform'; import UiAlert from '../components/ui/UiAlert.vue'; import UiButton from '../components/ui/UiButton.vue'; import UiEmptyState from '../components/ui/UiEmptyState.vue'; import UiFormField from '../components/ui/UiFormField.vue'; import UiLoadingState from '../components/ui/UiLoadingState.vue'; import UiPanel from '../components/ui/UiPanel.vue'; import UiTextInput from '../components/ui/UiTextInput.vue'; import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'; const addresses=ref<TelephonyAddress[]>([]); const loading=ref(false); const error=ref(''); const canManage=can('telephony.external_connectivity.manage'); const form=reactive({address_type:'e164',value:''}); async function load(){loading.value=true;try{addresses.value=(await identityApi.telephonyAddresses()).telephony_addresses}catch(e){error.value=apiErrorMessage(e)}finally{loading.value=false}} async function create(){try{await identityApi.createTelephonyAddress({...form});form.value='';await load()}catch(e){error.value=apiErrorMessage(e)}} async function activate(id:string){try{await identityApi.setTelephonyAddressState(id,'active');await load()}catch(e){error.value=apiErrorMessage(e)}} onMounted(load);watch(tenantContextVersion,load)</script>
<style scoped>.address-row { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; padding:.8rem 0; border-bottom:1px solid var(--color-border); }.address-row span { color:var(--color-text-muted); }</style>
