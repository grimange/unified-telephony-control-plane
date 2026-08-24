<template>
  <section
    class="workspace"
    aria-labelledby="runtime-nodes-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="runtime-nodes-title">
          Runtime nodes
        </h2>
        <p class="meta">
          Register and inspect telephony runtime nodes managed by the control plane. Add and operate an existing or externally managed runtime through UTCP.
        </p>
      </div>
      <UiStatusBadge
        class="live-updates-badge"
        :label="runtimeNodeRealtimeStatusText()"
        :category="runtimeNodeRealtimeStatusCategory"
      />
      <UiButton
        type="button"
        variant="secondary"
        :loading="runtimeNodesResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="load(true)"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      v-if="can('runtime.nodes.manage')"
      title="Add runtime"
      label="Runtime registry"
    >
      <div
        v-if="onboardingPath === null"
        class="choice-grid"
      >
        <UiButton
          v-if="managedRuntimeOptions.length > 0"
          type="button"
          variant="secondary"
          @click="selectManagedOnboarding()"
        >
          Create a new runtime
        </UiButton>
        <UiButton
          type="button"
          variant="secondary"
          @click="onboardingPath = 'external'"
        >
          Register an existing runtime
        </UiButton>
      </div>
      <p
        v-if="onboardingPath === null"
        class="meta"
      >
        Create a new runtime and UTCP will configure it automatically, or register an existing runtime as an advanced integration.
      </p>
      <form
        v-if="onboardingPath === 'external'"
        class="inline-form"
        @submit.prevent="runRuntimeAction(runtimeCreateActionKey, createRuntimeNode, 'Runtime node created.')"
      >
        <UiFormField
          id="runtime-name"
          label="Display name"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="runtimeNodeForm.name"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Runtime display name"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-slug"
          label="Slug"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="runtimeNodeForm.slug"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="runtime-slug"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-family"
          label="Runtime family"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="runtimeNodeForm.runtimeFamily"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option
                v-for="family in runtimeFamilyOptions"
                :key="family.key"
                :value="family.key"
              >
                {{ family.label }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-adapter"
          label="Adapter"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="runtimeNodeForm.adapterKey"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option
                v-if="runtimeNodeForm.adapterKey === ''"
                value=""
              >
                Select an adapter
              </option>
              <option
                v-for="adapter in adapterOptionsFor(runtimeNodeForm.runtimeFamily)"
                :key="adapter.key"
                :value="adapter.key"
              >
                {{ adapter.label }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :loading="runtimeActionSubmitting(runtimeCreateActionKey)"
          loading-label="Creating"
        >
          Create runtime node
        </UiButton>
      </form>
      <p
        v-if="onboardingPath === 'external'"
        class="meta"
      >
        Register a runtime whose infrastructure is managed outside UTCP.
      </p>
      <form
        v-if="onboardingPath === 'managed'"
        class="inline-form"
        @submit.prevent="runManagedProvisioning()"
      >
        <UiFormField
          v-if="managedRuntimeOptions.length > 1"
          id="managed-runtime-provider"
          label="Runtime"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              :model-value="managedRuntimeForm.adapterKey"
              :aria-describedby="describedBy"
              :invalid="invalid"
              required
              @update:model-value="selectManagedRuntimeOption(String($event))"
            >
              <option
                v-for="option in managedRuntimeOptions"
                :key="option.adapterKey"
                :value="option.adapterKey"
              >
                {{ option.providerLabel }} · {{ option.adapterLabel }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          v-if="deploymentTargets.length > 1"
          id="managed-deployment-target"
          label="Location"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="managedRuntimeForm.deploymentTargetId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              required
              :disabled="deploymentTargetsResource.state.status === 'loading'"
            >
              <option value="">
                Select a location
              </option>
              <option
                v-for="target in deploymentTargets"
                :key="target.id"
                :value="target.id"
              >
                {{ target.name }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="managed-runtime-name"
          label="Name"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="managedRuntimeForm.name"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Runtime display name"
              required
            />
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :disabled="!managedRuntimeForm.deploymentTargetId || !managedRuntimeForm.name.trim()"
          :loading="runtimeActionSubmitting(managedProvisionActionKey)"
          loading-label="Creating"
        >
          Create Runtime
        </UiButton>
      </form>
      <p
        v-if="onboardingPath === 'managed'"
        class="meta"
      >
        {{ selectedManagedRuntimeOption?.providerLabel ?? 'Managed runtime' }} · {{ deploymentTargets.length === 1 ? 'Local environment' : 'Choose a location' }}. UTCP will configure credentials, endpoints, and infrastructure automatically.
      </p>
      <UiAlert
        v-if="deploymentTargetsResource.state.status === 'error' || deploymentTargetsResource.state.status === 'forbidden'"
        variant="error"
        title="Deployment targets unavailable"
      >
        {{ deploymentTargetsResource.state.error }}
      </UiAlert>
      <UiButton
        v-if="onboardingPath !== null"
        type="button"
        variant="ghost"
        @click="resetOnboardingPath()"
      >
        Choose another onboarding path
      </UiButton>
    </UiPanel>

    <UiAlert
      v-if="runtimeActionError(runtimeCreateActionKey)"
      variant="error"
      title="Runtime node action failed"
    >
      {{ runtimeActionError(runtimeCreateActionKey) }}
    </UiAlert>
    <UiDataList
      :status="runtimeNodesResource.state.status"
      :error="runtimeNodesResource.state.error"
      :has-data="runtimeNodes.length > 0"
      title="Runtime node list"
      label="Runtime registry"
      loading-label="Loading runtime nodes."
      refreshing-label="Refreshing runtime nodes."
      empty-title="No runtime nodes"
      empty-message="No runtime nodes were returned."
      error-title="Runtime nodes unavailable"
      forbidden-title="Runtime nodes forbidden"
    >
      <template #actions>
        <UiListSummary
          :count="runtimeNodes.length"
          item-label="runtime nodes"
        />
      </template>
      <div class="data-table">
        <div
          v-for="node in runtimeNodes"
          :key="node.id"
          class="data-row runtime-row"
        >
          <span>
            <strong>{{ node.name }}</strong>
            <small>{{ node.slug }} · {{ node.runtime_family }} · {{ node.adapter_key }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="runtimeManagement(node).mode === 'managed' ? 'UTCP managed' : 'External'"
                :category="runtimeManagement(node).mode === 'managed' ? 'information' : 'neutral'"
              />
              <UiStatusBadge
                :label="runtimeNodePrimaryStatus(node)"
                :category="runtimeStatusCategory(runtimeNodePrimaryStatus(node).toLowerCase().replaceAll(' ', '_'))"
              />
              <UiStatusBadge
                v-if="runtimeEvidence[node.id]?.capabilities && capabilityDriftCount(node) > 0"
                :label="`capability drift ${capabilityDriftCount(node)}`"
                category="warning"
              />
            </span>
            <small
              v-if="runtimeManagement(node).mode === 'managed'"
              class="meta"
            >
              {{ managedProvisioningLabel(node) }} · Location: {{ runtimeManagement(node).provisioning_request?.deployment_target.name }}
            </small>
            <small
              v-if="runtimeManagement(node).mode === 'managed' && runtimeManagement(node).deprovisioning"
              class="meta"
            >
              Infrastructure: {{ managedDeprovisioningLabel(node) }}
            </small>
            <small
              v-if="node.desired_state === 'draining'"
              class="meta"
            >
              Draining — no new work will be assigned; existing work continues.
            </small>
            <small
              v-if="node.desired_state === 'drained'"
              class="meta"
            >
              Drained — no active workload remains; the node is excluded from placement.
            </small>
            <small
              v-if="runtimeEvidence[node.id]"
              class="meta"
            >
              Last observed: {{ displayValue(runtimeEvidence[node.id].observed_at) }} · Capability evidence: {{ runtimeEvidence[node.id].capabilities?.freshness ?? 'unknown' }}
            </small>
          </span>
          <span class="row-actions">
            <UiButton
              type="button"
              variant="secondary"
              :loading="nodeDetailStatus(node.id) === 'loading'"
              loading-label="Loading details"
              @click="toggleNodeDetails(node)"
            >
              {{ isNodeExpanded(node.id) ? 'Hide details' : 'Details' }}
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired'"
              type="button"
              variant="secondary"
              :disabled="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'))"
              :loading="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'))"
              @click="runRuntimeAction(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'), () => setRuntimeDesiredState(node.id, node.desired_state === 'active' ? 'draining' : 'active'), node.desired_state === 'draining' ? 'Runtime node drain cancelled.' : node.desired_state === 'drained' ? 'Runtime node reactivated.' : 'Runtime node drain requested.')"
            >
              {{ node.desired_state === 'active' ? 'Drain' : node.desired_state === 'draining' ? 'Cancel drain' : node.desired_state === 'drained' ? 'Reactivate' : 'Activate' }}
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage') && node.desired_state === 'drained'"
              type="button"
              variant="danger"
              :disabled="runtimeActionSubmitting(runtimeDecommissionActionKey(node))"
              :loading="runtimeActionSubmitting(runtimeDecommissionActionKey(node))"
              @click="requestRuntimeDecommission(node)"
            >
              Retire runtime
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage') && ['draft', 'active', 'draining'].includes(node.desired_state)"
              type="button"
              variant="danger"
              :disabled="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, 'disabled'))"
              :loading="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, 'disabled'))"
              @click="runRuntimeAction(runtimeDesiredStateActionKey(node, 'disabled'), () => setRuntimeDesiredState(node.id, 'disabled'), 'Runtime node disabled.')"
            >
              Disable
            </UiButton>
          </span>
          <div
            v-if="isNodeExpanded(node.id)"
            class="subgrid"
          >
            <UiLoadingState
              v-if="nodeDetailStatus(node.id) === 'loading'"
              label="Loading runtime node details."
            />
            <UiAlert
              v-if="nodeDetailStatus(node.id) === 'error' || nodeDetailStatus(node.id) === 'forbidden'"
              variant="error"
              title="Runtime node details unavailable"
            >
              {{ runtimeNodeDetailStates[node.id]?.error }}
            </UiAlert>
            <form
              v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
              class="inline-form"
              @submit.prevent="runRuntimeAction(runtimeNodeEditActionKey(node), () => saveRuntimeNodeEdit(node), 'Runtime node details saved.')"
            >
              <UiFormField
                :id="runtimeFieldId(node.id, 'name')"
                label="Display name"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="runtimeNodeEditForm(node).name"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    required
                  />
                </template>
              </UiFormField>
              <UiFormField
                :id="runtimeFieldId(node.id, 'region')"
                label="Placement region"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="runtimeNodeEditForm(node).placement_region"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  />
                </template>
              </UiFormField>
              <UiFormField
                :id="runtimeFieldId(node.id, 'zone')"
                label="Placement zone"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="runtimeNodeEditForm(node).placement_zone"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  />
                </template>
              </UiFormField>
              <UiFormField
                :id="runtimeFieldId(node.id, 'priority')"
                label="Placement priority"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model.number="runtimeNodeEditForm(node).placement_priority"
                    type="number"
                    min="1"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  />
                </template>
              </UiFormField>
              <UiFormField
                :id="runtimeFieldId(node.id, 'capacity')"
                label="Capacity weight"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model.number="runtimeNodeEditForm(node).capacity_weight"
                    type="number"
                    min="0"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  />
                </template>
              </UiFormField>
              <UiButton
                type="submit"
                :loading="runtimeActionSubmitting(runtimeNodeEditActionKey(node))"
                loading-label="Saving"
              >
                Save runtime details
              </UiButton>
            </form>
            <p class="meta">
              Runtime identity: {{ node.runtime_family }} / {{ node.adapter_key }}. Identity changes are lifecycle-guarded by the API.
            </p>
            <div class="review-card">
              <strong>Management</strong>
              <p class="meta">
                {{ runtimeManagement(node).mode === 'managed' ? 'UTCP managed' : 'External' }}
                <span v-if="runtimeManagement(node).provisioning_request"> · {{ runtimeManagement(node).provisioning_request?.deployment_target.name }}</span>
              </p>
              <p
                v-if="runtimeManagement(node).mode === 'managed'"
                class="meta"
              >
                Status: {{ runtimeNodePrimaryStatus(node) }}<span v-if="runtimeManagement(node).deprovisioning"> · Infrastructure: {{ managedDeprovisioningLabel(node) }}</span>
              </p>
              <p
                v-if="managedOperationFailure(node)"
                class="error-text"
              >
                Provisioning failed: {{ managedOperationFailure(node) }}
              </p>
            </div>
            <details class="advanced-details">
              <summary>Advanced diagnostics</summary>
              <form
                v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                class="inline-form"
                @submit.prevent="runRuntimeAction(runtimeEndpointAddActionKey(node), () => addRuntimeEndpoint(node.id), 'Runtime node endpoint added.')"
              >
                <UiFormField
                  :id="runtimeFieldId(node.id, 'endpoint-purpose')"
                  label="Purpose"
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiSelect
                      :id="id"
                      v-model="endpointForm.purpose"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                    >
                      <option value="control">
                        Control
                      </option>
                      <option value="events">
                        Events
                      </option>
                      <option value="health">
                        Health
                      </option>
                    </UiSelect>
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'endpoint-transport')"
                  label="Transport"
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiSelect
                      :id="id"
                      v-model="endpointForm.transport"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                    >
                      <option
                        v-for="option in endpointTransportOptions"
                        :key="option.key"
                        :value="option.key"
                      >
                        {{ option.label }}
                      </option>
                    </UiSelect>
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'endpoint-host')"
                  label="Host"
                  required
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model="endpointForm.host"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      autocomplete="off"
                      placeholder="runtime.local.test"
                      required
                    />
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'endpoint-port')"
                  label="Port"
                  required
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model.number="endpointForm.port"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      type="number"
                      min="1"
                      max="65535"
                      required
                    />
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'endpoint-path')"
                  label="Path"
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model="endpointForm.path"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      autocomplete="off"
                      placeholder="/optional-path"
                    />
                  </template>
                </UiFormField>
                <UiButton
                  type="submit"
                  :loading="runtimeActionSubmitting(runtimeEndpointAddActionKey(node))"
                  loading-label="Adding endpoint"
                >
                  Add endpoint
                </UiButton>
              </form>
              <div>
                <strong>Endpoints</strong>
                <div
                  v-for="endpoint in node.endpoints"
                  :key="endpoint.id"
                  class="meta inline-record"
                >
                  <form
                    v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                    class="inline-form endpoint-edit-form"
                    @submit.prevent="runRuntimeAction(runtimeEndpointUpdateActionKey(node, endpoint.id), () => updateRuntimeEndpoint(node.id, endpoint.id), 'Runtime endpoint updated.')"
                  >
                    <UiSelect
                      v-model="runtimeEndpointEditForm(endpoint).purpose"
                      aria-label="Endpoint purpose"
                    >
                      <option value="control">
                        Control
                      </option>
                      <option value="events">
                        Events
                      </option>
                      <option value="health">
                        Health
                      </option>
                    </UiSelect>
                    <UiSelect
                      v-model="runtimeEndpointEditForm(endpoint).transport"
                      aria-label="Endpoint transport"
                    >
                      <option
                        v-for="option in endpointTransportOptions"
                        :key="option.key"
                        :value="option.key"
                      >
                        {{ option.label }}
                      </option>
                    </UiSelect>
                    <UiTextInput
                      v-model="runtimeEndpointEditForm(endpoint).host"
                      aria-label="Endpoint host"
                      required
                    />
                    <UiTextInput
                      v-model.number="runtimeEndpointEditForm(endpoint).port"
                      aria-label="Endpoint port"
                      type="number"
                      min="1"
                      max="65535"
                      required
                    />
                    <UiTextInput
                      v-model="runtimeEndpointEditForm(endpoint).path"
                      aria-label="Endpoint path"
                      placeholder="Path"
                    />
                    <UiSelect
                      v-model="runtimeEndpointEditForm(endpoint).tls_mode"
                      aria-label="Endpoint TLS mode"
                    >
                      <option
                        v-for="option in endpointTlsModeOptions"
                        :key="option.key"
                        :value="option.key"
                      >
                        {{ option.label }}
                      </option>
                    </UiSelect>
                    <UiTextInput
                      v-model.number="runtimeEndpointEditForm(endpoint).priority"
                      aria-label="Endpoint priority"
                      type="number"
                      min="0"
                    />
                    <UiSelect
                      v-model="runtimeEndpointEditForm(endpoint).enabled"
                      aria-label="Endpoint enabled"
                    >
                      <option value="true">
                        Enabled
                      </option>
                      <option value="false">
                        Disabled
                      </option>
                    </UiSelect>
                    <UiButton
                      type="submit"
                      variant="secondary"
                      :loading="runtimeActionSubmitting(runtimeEndpointUpdateActionKey(node, endpoint.id))"
                      loading-label="Saving endpoint"
                    >
                      Save endpoint
                    </UiButton>
                  </form>
                  <span v-else>{{ endpoint.purpose }} {{ endpoint.transport }}://{{ endpoint.host }}:{{ endpoint.port }}{{ endpoint.path ?? '' }}</span>
                  <UiButton
                    v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                    type="button"
                    variant="ghost"
                    :disabled="runtimeActionSubmitting(runtimeEndpointRemoveActionKey(node, endpoint.id))"
                    :loading="runtimeActionSubmitting(runtimeEndpointRemoveActionKey(node, endpoint.id))"
                    @click="requestRuntimeEndpointRemoval(node, endpoint.id)"
                  >
                    Remove
                  </UiButton>
                </div>
              </div>
              <form
                v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                class="inline-form"
                @submit.prevent="runRuntimeAction(runtimeCapabilitiesActionKey(node), () => setRuntimeCapabilities(node.id), 'Runtime node capabilities updated.')"
              >
                <label
                  v-for="capability in capabilityOptionsFor(node)"
                  :key="capability"
                  class="check-label"
                  :for="capabilityInputId(node.id, capability)"
                >
                  <input
                    :id="capabilityInputId(node.id, capability)"
                    v-model="runtimeCapabilitySelections[node.id]"
                    type="checkbox"
                    :value="capability"
                  >
                  {{ capabilityLabel(capability) }}
                </label>
                <UiButton
                  type="submit"
                  :loading="runtimeActionSubmitting(runtimeCapabilitiesActionKey(node))"
                  loading-label="Saving capabilities"
                >
                  Set capabilities
                </UiButton>
              </form>
              <div>
                <strong>Declared capabilities</strong>
                <p class="meta">
                  {{ node.capabilities.join(', ') || 'None' }}
                </p>
              </div>
              <form
                v-if="can('runtime.credentials.rotate') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                class="inline-form"
                @submit.prevent="runRuntimeAction(runtimeCredentialCreateActionKey(node), () => createRuntimeCredential(node.id), 'Runtime node credential saved.')"
              >
                <UiFormField
                  :id="runtimeFieldId(node.id, 'credential-type')"
                  label="Credential type"
                  required
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model="credentialForm.type"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      autocomplete="off"
                      placeholder="control-api"
                      required
                    />
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'credential-identifier')"
                  label="Identifier"
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model="credentialForm.identifier"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      autocomplete="off"
                      placeholder="identifier"
                    />
                  </template>
                </UiFormField>
                <UiFormField
                  :id="runtimeFieldId(node.id, 'credential-secret')"
                  label="Write-only secret"
                  help="Secrets are submitted once and cannot be retrieved after submission."
                  required
                >
                  <template #default="{ id, describedBy, invalid }">
                    <UiTextInput
                      :id="id"
                      v-model="credentialForm.secret"
                      :aria-describedby="describedBy"
                      :invalid="invalid"
                      autocomplete="new-password"
                      type="password"
                      placeholder="Write-only secret"
                      required
                    />
                  </template>
                </UiFormField>
                <UiButton
                  type="submit"
                  :loading="runtimeActionSubmitting(runtimeCredentialCreateActionKey(node))"
                  loading-label="Saving credential"
                >
                  Save credential
                </UiButton>
              </form>
              <div>
                <strong>Credentials</strong>
                <p
                  v-for="credential in node.credentials"
                  :key="credential.id"
                  class="meta inline-record"
                >
                  <span>{{ credential.type }} v{{ credential.version }} · {{ credential.status }} · fingerprint {{ credential.fingerprint.slice(0, 12) }}</span>
                  <UiButton
                    v-if="can('runtime.credentials.rotate') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                    type="button"
                    variant="ghost"
                    :disabled="runtimeActionSubmitting(runtimeCredentialRotateActionKey(node, credential.id))"
                    :loading="runtimeActionSubmitting(runtimeCredentialRotateActionKey(node, credential.id))"
                    @click="runRuntimeAction(runtimeCredentialRotateActionKey(node, credential.id), () => rotateRuntimeCredential(node.id, credential.id, credential.type), 'Runtime node credential rotated.')"
                  >
                    Rotate
                  </UiButton>
                  <UiButton
                    v-if="can('runtime.credentials.rotate') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed' && canRetireCredential(node, credential)"
                    type="button"
                    variant="danger"
                    :disabled="runtimeActionSubmitting(runtimeCredentialRetireActionKey(node, credential.id))"
                    :loading="runtimeActionSubmitting(runtimeCredentialRetireActionKey(node, credential.id))"
                    @click="runRuntimeAction(runtimeCredentialRetireActionKey(node, credential.id), () => retireRuntimeCredential(node.id, credential.id), 'Runtime node credential retired.')"
                  >
                    Retire
                  </UiButton>
                </p>
                <UiAlert
                  variant="info"
                  title="Write-only credentials"
                >
                  Secrets are write-only and cannot be retrieved after submission.
                </UiAlert>
              </div>
              <form
                v-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed' && adapterConfigurationSupported(node) && adapterConfigurationDescriptorsFor(node).length > 0"
                class="inline-form"
                @submit.prevent="runRuntimeAction(runtimeAdapterConfigurationActionKey(node), () => saveRuntimeAdapterConfiguration(node), 'Runtime node adapter configuration saved.')"
              >
                <RuntimeNodeCatalogField
                  v-for="field in adapterConfigurationDescriptorsFor(node)"
                  :key="field.key"
                  :field="field"
                  :runtime-node-id="node.id"
                  :model-value="adapterConfigurationForm(node.id)[field.key]"
                  :error="adapterConfigurationFieldError(node.id, field.key)"
                  :disabled="runtimeActionSubmitting(runtimeAdapterConfigurationActionKey(node))"
                  @update:model-value="setAdapterConfigurationFormValue(node.id, field.key, $event)"
                />
                <UiAlert
                  v-if="unsupportedAdapterConfigurationFields(node).length > 0"
                  :variant="adapterConfigurationSubmissionBlocked(node) ? 'error' : 'warning'"
                  title="Unsupported adapter configuration"
                >
                  {{ unsupportedAdapterConfigurationSummary(node) }}
                </UiAlert>
                <UiAlert
                  v-if="runtimeActionError(runtimeAdapterConfigurationActionKey(node))"
                  variant="error"
                  title="Adapter configuration save failed"
                >
                  {{ runtimeActionError(runtimeAdapterConfigurationActionKey(node)) }}
                </UiAlert>
                <UiButton
                  type="submit"
                  :disabled="adapterConfigurationSubmissionBlocked(node)"
                  :loading="runtimeActionSubmitting(runtimeAdapterConfigurationActionKey(node))"
                  loading-label="Saving adapter configuration"
                >
                  Save adapter configuration
                </UiButton>
              </form>
              <UiAlert
                v-else-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed' && adapterConfigurationSupported(node)"
                variant="warning"
                title="Adapter configuration unavailable"
              >
                This adapter reports configuration support but did not provide writable field descriptors.
              </UiAlert>
              <UiAlert
                v-else-if="can('runtime.nodes.manage') && node.desired_state !== 'retired' && runtimeManagement(node).mode !== 'managed'"
                variant="info"
                title="Adapter configuration not available"
              >
                This adapter does not expose writable configuration fields.
              </UiAlert>
              <div v-if="runtimeEvidence[node.id]">
                <strong>Runtime evidence</strong>
                <p class="meta">
                  Desired state: {{ runtimeEvidence[node.id].desired_state }} · Observed state: {{ runtimeEvidence[node.id].observed_state }}
                </p>
                <p class="meta">
                  Last observation: {{ displayValue(runtimeEvidence[node.id].observed_at) }}
                </p>
                <p class="meta">
                  Configuration generation: {{ runtimeEvidence[node.id].desired_configuration_generation }} · Observed generation: {{ displayValue(runtimeEvidence[node.id].observed_configuration_generation) }}
                </p>
                <p class="meta">
                  Event connection status: {{ runtimeEvidence[node.id].connection.state }} · Latest connection time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_opened_at) }} · Latest disconnect time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_closed_at) }}
                </p>
                <p class="meta">
                  Reconciliation state: {{ runtimeEvidence[node.id].reconciliation.state }} · Next retry: {{ displayValue(runtimeEvidence[node.id].reconciliation.next_retry_at) }}
                </p>
                <p class="meta">
                  Sanitized failure: {{ displayValue(runtimeEvidence[node.id].reconciliation.sanitized_failure_code ?? runtimeEvidence[node.id].reconciliation.sanitized_failure_class) }}
                </p>
                <p class="meta">
                  Last successful inspection: {{ displayValue(runtimeEvidence[node.id].inspection.last_success_at) }}
                </p>
                <div v-if="runtimeEvidence[node.id]?.capabilities">
                  <strong>Capability evidence</strong>
                  <p class="meta">
                    Declared: {{ runtimeEvidence[node.id].capabilities.declared.join(', ') || 'None' }}
                  </p>
                  <p class="meta">
                    Observed: {{ runtimeEvidence[node.id].capabilities.observed === null ? 'Not yet observed' : (runtimeEvidence[node.id].capabilities.observed?.join(', ') || 'None') }}
                  </p>
                  <p
                    v-if="runtimeEvidence[node.id].capabilities.declared_not_observed.length > 0"
                    class="meta"
                  >
                    Declared but not observed: {{ runtimeEvidence[node.id].capabilities.declared_not_observed.join(', ') }}
                  </p>
                  <p
                    v-if="runtimeEvidence[node.id].capabilities.observed_not_declared.length > 0"
                    class="meta"
                  >
                    Observed but not declared: {{ runtimeEvidence[node.id].capabilities.observed_not_declared.join(', ') }}
                  </p>
                  <p class="meta">
                    Capability evidence freshness: {{ runtimeEvidence[node.id].capabilities.freshness }} · Observed at: {{ displayValue(runtimeEvidence[node.id].capabilities.observed_at) }}
                  </p>
                </div>
                <div v-if="runtimeEvidence[node.id]?.drain">
                  <p class="meta">
                    Drain status: {{ runtimeEvidence[node.id]?.drain?.drain_state }} · Remaining work: {{ runtimeEvidence[node.id]?.drain?.remaining_work }}
                  </p>
                  <p
                    v-if="runtimeEvidence[node.id]?.drain?.timed_out"
                    class="meta"
                  >
                    Drain timed out — {{ runtimeEvidence[node.id]?.drain?.remaining_work }} active bindings remain; the node is still cordoned.
                  </p>
                </div>
                <div v-if="runtimeEvidence[node.id]?.decommission">
                  <p class="meta">
                    Decommission: {{ runtimeEvidence[node.id]?.decommission?.status }}
                  </p>
                  <p
                    v-if="runtimeEvidence[node.id]?.decommission?.failure_message"
                    class="meta"
                  >
                    Decommission failed: {{ runtimeEvidence[node.id]?.decommission?.failure_message }}
                  </p>
                </div>
              </div>
              <div v-if="runtimeHistory[node.id]?.history.length">
                <strong>History</strong>
                <p
                  v-for="entry in runtimeHistory[node.id].history"
                  :key="entry.id"
                  class="meta"
                >
                  {{ entry.timestamp }} · {{ entry.action }} · {{ entry.actor }} · {{ entry.summary }}
                </p>
                <UiButton
                  v-if="runtimeHistory[node.id].pagination.has_more"
                  type="button"
                  variant="secondary"
                  @click="loadMoreRuntimeHistory(node.id)"
                >
                  Load more history
                </UiButton>
              </div>
            </details>
          </div>
        </div>
      </div>
    </UiDataList>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import RuntimeNodeCatalogField from '../components/runtime/RuntimeNodeCatalogField.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncAction, useAsyncActionMap, useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { identityApi, type RuntimeNode } from '../api/platform'
import { router } from '../router'
import { managedDeprovisioningLabel, managedProvisioningLabel, runtimeNodePrimaryStatus } from './runtimeNodeManagementPresentation'
import { catalogOptions } from './runtimeCatalogPresentation'
import { managedRuntimeOptions as deriveManagedRuntimeOptions } from './runtimeManagedOptions'
import {
  disconnectRuntimeNodeRealtime,
  resynchronizeRuntimeNodeRealtime,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeStatusText,
  subscribeRuntimeNodeRealtime,
} from '../realtime/runtimeNodeRealtime'
import { notify } from '../state/notifications'
import {
  adapterConfigurationSupported,
  adapterConfigurationDescriptorsFor,
  adapterConfigurationFieldError,
  adapterConfigurationForm,
  adapterConfigurationSubmissionBlocked,
  adapterOptionsFor,
  addRuntimeEndpoint,
  apiErrorMessage,
  can,
  canRetireCredential,
  capabilityLabel,
  capabilityOptionsFor,
  createRuntimeCredential,
  createRuntimeNode,
  credentialForm,
  displayValue,
  decommissionRuntimeNode,
  endpointForm,
  loadRuntimeNodeDetails,
  loadMoreRuntimeHistory,
  reloadRuntimeNodeDetails,
  refreshRuntimeNodes,
  removeRuntimeEndpoint,
  runtimeEndpointEditForm,
  runtimeNodeEditForm,
  saveRuntimeNodeEdit,
  updateRuntimeEndpoint,
  retireRuntimeCredential,
  rotateRuntimeCredential,
  runtimeCapabilitySelections,
  runtimeEvidence,
  runtimeFamilyOptions,
  runtimeHistory,
  runtimeNodeDetailStates,
  runtimeNodeForm,
  runtimeNodes,
  runtimeCatalog,
  saveRuntimeAdapterConfiguration,
  session,
  setRuntimeCapabilities,
  setAdapterConfigurationFormValue,
  setRuntimeDesiredState,
  tenantContextVersion,
  unsupportedAdapterConfigurationFields,
} from '../state/appState'

const expandedRuntimeNodeIds = ref<string[]>([])
useListQueryState(router, {})
const runtimeNodesResource = useAsyncResource(refreshRuntimeNodes, {
  isEmpty: () => runtimeNodes.value.length === 0,
  getErrorMessage: apiErrorMessage,
})
const detailAction = useAsyncAction(async (node: RuntimeNode) => loadRuntimeNodeDetails(node, true), {
  getErrorMessage: apiErrorMessage,
})
const runtimeActions = useAsyncActionMap<void>({
  getErrorMessage: apiErrorMessage,
})
const runtimeCreateActionKey = 'runtime-node:create'
const managedProvisionActionKey = 'runtime-node:managed-provision'
const onboardingPath = ref<'managed' | 'external' | null>(null)
const managedProvisioningIdempotencyKey = ref('')
const managedRuntimeForm = ref({
  runtimeFamily: '',
  adapterKey: '',
  deploymentTargetId: '',
  name: '',
})
const deploymentTargets = ref<Awaited<ReturnType<typeof identityApi.deploymentTargets>>['deployment_targets']>([])
const deploymentTargetsResource = useAsyncResource(async () => {
  const response = await identityApi.deploymentTargets()
  deploymentTargets.value = response.deployment_targets

  return response.deployment_targets
}, {
  isEmpty: (targets) => targets.length === 0,
  getErrorMessage: apiErrorMessage,
})
let backgroundedAt = 0

const runtimeNodeRealtimeStatusCategory = computed((): 'success' | 'warning' | 'danger' | 'neutral' | 'information' => {
  if (runtimeNodeRealtimeConnectionState.value === 'connected') return 'success'
  if (runtimeNodeRealtimeConnectionState.value === 'connecting') return 'information'
  if (runtimeNodeRealtimeConnectionState.value === 'reconnecting') return 'warning'
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return 'warning'
  if (runtimeNodeRealtimeConnectionState.value === 'disconnected') return 'danger'

  return 'neutral'
})

const endpointTransportOptions = computed(() => catalogOptions(runtimeCatalog.value?.endpoint_transports))
const endpointTlsModeOptions = computed(() => catalogOptions(runtimeCatalog.value?.endpoint_tls_modes))
const managedRuntimeOptions = computed(() => deriveManagedRuntimeOptions(runtimeCatalog.value))
const selectedManagedRuntimeOption = computed(() => managedRuntimeOptions.value.find((option) =>
  option.runtimeFamily === managedRuntimeForm.value.runtimeFamily && option.adapterKey === managedRuntimeForm.value.adapterKey,
) ?? managedRuntimeOptions.value[0] ?? null)

function selectManagedRuntimeOption(adapterKey?: string): void {
  const option = managedRuntimeOptions.value.find((candidate) => candidate.adapterKey === (adapterKey ?? managedRuntimeForm.value.adapterKey))
    ?? managedRuntimeOptions.value[0]
  if (!option) {
    managedRuntimeForm.value.runtimeFamily = ''
    managedRuntimeForm.value.adapterKey = ''
    return
  }

  managedRuntimeForm.value.runtimeFamily = option.runtimeFamily
  managedRuntimeForm.value.adapterKey = option.adapterKey
}

watch(managedRuntimeOptions, () => selectManagedRuntimeOption())

function runtimeStatusCategory(status: string): 'success' | 'warning' | 'danger' | 'neutral' | 'information' {
  if (['active', 'ready', 'healthy', 'observed'].includes(status)) return 'success'
  if (['draft', 'draining', 'recovering', 'unobserved', 'degraded'].includes(status)) return 'warning'
  if (status === 'drained') return 'information'
  if (['failed', 'unavailable', 'disabled', 'retired'].includes(status)) return 'danger'

  return 'neutral'
}

function managedOperationFailure(node: RuntimeNode): string {
  const management = runtimeManagement(node)
  const failure = management.deprovisioning?.failure ?? management.provisioning?.failure
  if (!failure) return ''

  return [failure.class, failure.code].filter(Boolean).join(': ')
}

function runtimeManagement(node: RuntimeNode): NonNullable<RuntimeNode['management']> {
  return node.management ?? {
    mode: 'external',
    provisioning_request: null,
    provisioning: null,
    deprovisioning: null,
  }
}

function selectManagedOnboarding(): void {
  onboardingPath.value = 'managed'
  managedProvisioningIdempotencyKey.value = ''
  selectManagedRuntimeOption()
  if (deploymentTargetsResource.state.status === 'idle' || deploymentTargetsResource.state.status === 'error') {
    void deploymentTargetsResource.load().then(() => {
      if (deploymentTargets.value.length === 1) managedRuntimeForm.value.deploymentTargetId = deploymentTargets.value[0].id
    })
  } else if (deploymentTargets.value.length === 1) {
    managedRuntimeForm.value.deploymentTargetId = deploymentTargets.value[0].id
  }
}

function resetOnboardingPath(): void {
  onboardingPath.value = null
  managedRuntimeForm.value = {
    runtimeFamily: '',
    adapterKey: '',
    deploymentTargetId: '',
    name: '',
  }
}

function idempotencyKey(): string {
  const cryptoApi = globalThis.crypto
  if (cryptoApi?.randomUUID) return cryptoApi.randomUUID()

  return `managed-runtime-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

async function runManagedProvisioning(): Promise<void> {
  await runtimeActions.run(managedProvisionActionKey, async () => {
    const response = await identityApi.createRuntimeProvisioning({
      deployment_target_id: managedRuntimeForm.value.deploymentTargetId,
      runtime_family: managedRuntimeForm.value.runtimeFamily,
      adapter_key: managedRuntimeForm.value.adapterKey,
      name: managedRuntimeForm.value.name.trim(),
    }, managedProvisioningIdempotencyKey.value || (managedProvisioningIdempotencyKey.value = idempotencyKey()))
    await refreshRuntimeNodes()
    const runtimeNodeId = response.provisioning_request.runtime_node.id
    if (!expandedRuntimeNodeIds.value.includes(runtimeNodeId)) {
      expandedRuntimeNodeIds.value = [...expandedRuntimeNodeIds.value, runtimeNodeId]
    }
    onboardingPath.value = null
    managedProvisioningIdempotencyKey.value = ''
    managedRuntimeForm.value = {
      runtimeFamily: '',
      adapterKey: '',
      deploymentTargetId: '',
      name: '',
    }
  })
  if (runtimeActions.stateFor(managedProvisionActionKey).status === 'succeeded') {
    notify({ variant: 'success', title: 'Managed runtime requested', message: 'UTCP is provisioning the runtime automatically.' })
  } else if (runtimeActions.stateFor(managedProvisionActionKey).status === 'failed') {
    notify({ variant: 'error', title: 'Provisioning failed', message: runtimeActions.stateFor(managedProvisionActionKey).error })
  }
}

function safeDomId(value: string): string {
  return value.replace(/[^A-Za-z0-9_-]/g, '-')
}

function runtimeFieldId(runtimeNodeId: string, field: string): string {
  return `${field}-${safeDomId(runtimeNodeId)}`
}

function runtimeDesiredStateActionKey(node: RuntimeNode, desiredState: string): string {
  return `runtime-node:${node.id}:desired:${desiredState}`
}

function runtimeDecommissionActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:decommission`
}

function runtimeEndpointAddActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:endpoint:add`
}

function runtimeEndpointRemoveActionKey(node: RuntimeNode, endpointId: string): string {
  return `runtime-node:${node.id}:endpoint:${endpointId}:remove`
}

function runtimeNodeEditActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:edit`
}

function runtimeEndpointUpdateActionKey(node: RuntimeNode, endpointId: string): string {
  return `runtime-node:${node.id}:endpoint:${endpointId}:update`
}

function capabilityDriftCount(node: RuntimeNode): number {
  const capabilities = runtimeEvidence[node.id]?.capabilities

  return (capabilities?.declared_not_observed.length ?? 0) + (capabilities?.observed_not_declared.length ?? 0)
}

function runtimeCapabilitiesActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:capabilities`
}

function capabilityInputId(nodeId: string, capability: string): string {
  return `runtime-capability-${nodeId}-${capability.replace(/[^a-z0-9_-]/gi, '-')}`
}

function runtimeCredentialCreateActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:credential:create`
}

function runtimeCredentialRotateActionKey(node: RuntimeNode, credentialId: string): string {
  return `runtime-node:${node.id}:credential:${credentialId}:rotate`
}

function runtimeCredentialRetireActionKey(node: RuntimeNode, credentialId: string): string {
  return `runtime-node:${node.id}:credential:${credentialId}:retire`
}

function runtimeAdapterConfigurationActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:adapter-configuration`
}

function runtimeActionSubmitting(key: string): boolean {
  return runtimeActions.isSubmitting(key)
}

function runtimeActionError(key: string): string {
  const state = runtimeActions.stateFor(key)

  return state.status === 'failed' ? state.error : ''
}

function unsupportedAdapterConfigurationSummary(node: RuntimeNode): string {
  return unsupportedAdapterConfigurationFields(node)
    .map((field) => `${field.key} (${field.input_type})`)
    .join(', ')
}

function isNodeExpanded(runtimeNodeId: string): boolean {
  return expandedRuntimeNodeIds.value.includes(runtimeNodeId)
}

function nodeDetailStatus(runtimeNodeId: string): string {
  return runtimeNodeDetailStates[runtimeNodeId]?.status ?? 'idle'
}

async function toggleNodeDetails(node: RuntimeNode): Promise<void> {
  if (isNodeExpanded(node.id)) {
    expandedRuntimeNodeIds.value = expandedRuntimeNodeIds.value.filter((runtimeNodeId) => runtimeNodeId !== node.id)

    return
  }

  expandedRuntimeNodeIds.value = [...expandedRuntimeNodeIds.value, node.id]
  await detailAction.run(node)
  if (detailAction.state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'Runtime node details unavailable',
      message: detailAction.state.error,
    })
  }
}

async function runRuntimeAction(key: string, action: () => Promise<void>, successMessage: string): Promise<void> {
  await runtimeActions.run(key, action)
  if (runtimeActions.stateFor(key).status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'Runtime node updated',
      message: successMessage,
    })

    return
  }

  if (runtimeActions.stateFor(key).status === 'failed') {
    notify({
      variant: 'error',
      title: 'Runtime node action failed',
      message: runtimeActions.stateFor(key).error,
    })
  }
}

async function requestRuntimeDecommission(node: RuntimeNode): Promise<void> {
  const message = runtimeManagement(node).mode === 'managed'
    ? 'Retire this runtime? It will permanently leave service. UTCP-managed runtime resources will be removed automatically. The historical runtime record will remain.'
    : 'Retire this runtime? It will permanently leave UTCP service. Infrastructure managed outside UTCP will not be deleted. The historical runtime record will remain.'
  if (!window.confirm(message)) {
    return
  }

  await runRuntimeAction(runtimeDecommissionActionKey(node), () => decommissionRuntimeNode(node.id), 'Runtime node decommission requested.')
}

async function requestRuntimeEndpointRemoval(node: RuntimeNode, endpointId: string): Promise<void> {
  if (!window.confirm('Remove this runtime endpoint? Existing runtime operations may no longer be able to use it.')) {
    return
  }

  await runRuntimeAction(runtimeEndpointRemoveActionKey(node, endpointId), () => removeRuntimeEndpoint(node.id, endpointId), 'Runtime node endpoint removed.')
}

async function load(refreshExpandedDetails = false): Promise<void> {
  await runtimeNodesResource.load()
  subscribeAfterCanonicalSnapshot()

  if (refreshExpandedDetails) {
    await Promise.all([...expandedRuntimeNodeIds.value].map((runtimeNodeId) => reloadRuntimeNodeDetails(runtimeNodeId)))
  }
}

function subscribeAfterCanonicalSnapshot(): void {
  const activeTenantId = session.value?.active_tenant?.tenant_id ?? ''
  if (!session.value || activeTenantId === '' || !['success', 'empty'].includes(runtimeNodesResource.state.status)) {
    disconnectRuntimeNodeRealtime()

    return
  }

  subscribeRuntimeNodeRealtime({
    tenantId: activeTenantId,
    refreshList: async () => {
      await runtimeNodesResource.load()
    },
    refreshNodeDetails: async (runtimeNodeId: string) => {
      const node = runtimeNodes.value.find((candidate) => candidate.id === runtimeNodeId)
      if (node) await loadRuntimeNodeDetails(node, true)
    },
    openRuntimeNodeIds: () => [...expandedRuntimeNodeIds.value],
    sessionActive: () => session.value?.active_tenant?.tenant_id === activeTenantId,
  })
}

function handleVisibilityChange(): void {
  const browserDocument = globalThis.document
  if (!browserDocument) return

  if (browserDocument.visibilityState === 'hidden') {
    backgroundedAt = Date.now()

    return
  }

  if (backgroundedAt > 0 && Date.now() - backgroundedAt >= 5_000) {
    void resynchronizeRuntimeNodeRealtime()
  }
  backgroundedAt = 0
}

watch(
  tenantContextVersion,
  () => {
    expandedRuntimeNodeIds.value = []
    void load()
  },
  { immediate: true },
)

onMounted(() => {
  globalThis.document?.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  globalThis.document?.removeEventListener('visibilitychange', handleVisibilityChange)
  disconnectRuntimeNodeRealtime()
})
</script>

<style scoped>
.choice-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.review-card {
  display: grid;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--color-border, #d7dce5);
  border-radius: 0.75rem;
}

.review-list {
  display: grid;
  grid-template-columns: minmax(8rem, 0.7fr) minmax(0, 1.3fr);
  gap: 0.5rem 1rem;
  margin: 0;
}

.review-list dt {
  color: var(--color-text-muted, #667085);
  font-weight: 600;
}

.review-list dd {
  margin: 0;
}

.error-text {
  color: var(--color-danger, #b42318);
}

@media (max-width: 720px) {
  .choice-grid,
  .review-list {
    grid-template-columns: 1fr;
  }
}
</style>
