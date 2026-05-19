@php
    $quickPartnerModalId = $quickPartnerModalId ?? 'quickBusinessPartnerModal';
    $quickPartnerFormId = $quickPartnerFormId ?? ($quickPartnerModalId . 'Form');
    $quickPartnerSelectTarget = $quickPartnerSelectTarget ?? 'business_partner_id';
    $quickPartnerRoute = $quickPartnerRoute ?? route('business-partners.store');
    $quickClientModalId = $quickClientModalId ?? 'quickPartnerClientModal';
    $quickClientFormId = $quickClientFormId ?? ($quickClientModalId . 'Form');
    $quickClientSelectTarget = $quickClientSelectTarget ?? 'partner_client_id';
    $quickClientPartnerSource = $quickClientPartnerSource ?? 'business_partner_id';
    $quickClientRouteTemplate = $quickClientRouteTemplate ?? url('/business-partners/__PARTNER__/clients');
    $quickStateOptions = \App\Models\Customer::indianStates();
@endphp

@once
<style>
    .quick-party-modal {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: grid;
        place-items: center;
        padding: 12px;
        overflow: hidden;
    }
    .quick-party-modal[hidden] { display: none !important; }
    .quick-party-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.52);
    }
    .quick-party-dialog {
        position: relative;
        width: 100%;
        max-width: 620px;
        max-height: min(90vh, calc(100dvh - 24px));
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 20px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        overflow: hidden;
    }
    .quick-party-header {
        flex: 0 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px 14px;
        border-bottom: 1px solid #e2e8f0;
    }
    .quick-party-header h3 {
        margin: 0;
        font-size: 18px;
        color: #0f172a;
        line-height: 1.25;
    }
    .quick-party-header p {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.45;
    }
    .quick-party-close {
        width: 44px;
        height: 44px;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #f8fafc;
        color: #334155;
        cursor: pointer;
        font-size: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .quick-party-form {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .quick-party-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 14px 18px 18px;
        display: grid;
        gap: 12px;
    }
    .quick-party-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 12px;
    }
    .quick-party-col-4 { grid-column: span 4; }
    .quick-party-col-6 { grid-column: span 6; }
    .quick-party-col-8 { grid-column: span 8; }
    .quick-party-col-12 { grid-column: span 12; }
    .quick-party-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .quick-party-field label {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    .quick-party-field input,
    .quick-party-field select,
    .quick-party-field textarea {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        line-height: 1.45;
    }
    .quick-party-field textarea { min-height: 92px; resize: vertical; }
    .quick-party-field.is-error input,
    .quick-party-field.is-error select,
    .quick-party-field.is-error textarea {
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        background: #fff7f7;
    }
    .quick-party-error {
        color: #b91c1c;
        font-size: 12px;
        line-height: 1.4;
    }
    .quick-party-alert {
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 12px;
        border: 1px solid transparent;
    }
    .quick-party-alert.is-error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .quick-party-inline-note {
        border-radius: 12px;
        border: 1px solid #dbe3ef;
        background: #f8fafc;
        padding: 10px 12px;
        color: #475569;
        font-size: 13px;
        line-height: 1.45;
    }
    .quick-party-actions {
        flex: 0 0 auto;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        padding: 14px 18px 16px;
        border-top: 1px solid #e2e8f0;
        background: #fff;
    }
    .quick-party-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        border: 1px solid transparent;
        cursor: pointer;
    }
    .quick-party-btn-primary { background: #0f172a; color: #fff; }
    .quick-party-btn-light { background: #fff; color: #334155; border-color: #cbd5e1; }
    @media (max-width: 640px) {
        .quick-party-modal {
            padding: 8px;
            place-items: end center;
        }
        .quick-party-dialog {
            max-width: 100%;
            max-height: min(92vh, calc(100dvh - 16px));
            border-radius: 18px;
        }
        .quick-party-header,
        .quick-party-body,
        .quick-party-actions {
            padding-left: 14px;
            padding-right: 14px;
        }
        .quick-party-col-4,
        .quick-party-col-6,
        .quick-party-col-8 { grid-column: span 12; }
    }
    @media (max-width: 420px) {
        .quick-party-actions {
            display: grid;
            grid-template-columns: 1fr;
        }
        .quick-party-btn {
            width: 100%;
        }
    }
</style>
@endonce

<div class="quick-party-modal" id="{{ $quickPartnerModalId }}" hidden aria-hidden="true">
    <div class="quick-party-backdrop" data-close-modal="overlay"></div>
    <div class="quick-party-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickPartnerModalId }}Title">
        <div class="quick-party-header">
            <div>
                <h3 id="{{ $quickPartnerModalId }}Title">Add Business Partner</h3>
                <p>Create a partner without leaving this order. The new partner will be selected automatically.</p>
            </div>
            <button type="button" class="quick-party-close" data-close-modal="button" aria-label="Close">&times;</button>
        </div>

        <form id="{{ $quickPartnerFormId }}" action="{{ $quickPartnerRoute }}" method="POST" class="quick-party-form" novalidate>
            @csrf
            <div class="quick-party-body">
                <div class="quick-party-alert" data-role="partner-alert" hidden></div>
                <div class="quick-party-grid">
                    <div class="quick-party-field quick-party-col-6" data-field="business_name">
                        <label for="{{ $quickPartnerModalId }}BusinessName">Business Name</label>
                        <input type="text" name="business_name" id="{{ $quickPartnerModalId }}BusinessName" placeholder="Business name" required>
                        <div class="quick-party-error" data-error-for="business_name"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-6" data-field="contact_person">
                        <label for="{{ $quickPartnerModalId }}ContactPerson">Contact Person</label>
                        <input type="text" name="contact_person" id="{{ $quickPartnerModalId }}ContactPerson" placeholder="Contact person">
                        <div class="quick-party-error" data-error-for="contact_person"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="phone">
                        <label for="{{ $quickPartnerModalId }}Phone">Phone</label>
                        <input type="text" name="phone" id="{{ $quickPartnerModalId }}Phone" placeholder="Phone">
                        <div class="quick-party-error" data-error-for="phone"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="whatsapp">
                        <label for="{{ $quickPartnerModalId }}Whatsapp">WhatsApp</label>
                        <input type="text" name="whatsapp" id="{{ $quickPartnerModalId }}Whatsapp" placeholder="WhatsApp">
                        <div class="quick-party-error" data-error-for="whatsapp"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="email">
                        <label for="{{ $quickPartnerModalId }}Email">Email</label>
                        <input type="email" name="email" id="{{ $quickPartnerModalId }}Email" placeholder="Email">
                        <div class="quick-party-error" data-error-for="email"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="city">
                        <label for="{{ $quickPartnerModalId }}City">City</label>
                        <input type="text" name="city" id="{{ $quickPartnerModalId }}City" placeholder="City">
                        <div class="quick-party-error" data-error-for="city"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="state">
                        <label for="{{ $quickPartnerModalId }}State">State</label>
                        <select name="state" id="{{ $quickPartnerModalId }}State">
                            <option value="">Select state</option>
                            @foreach($quickStateOptions as $state)
                                <option value="{{ $state }}">{{ $state }}</option>
                            @endforeach
                        </select>
                        <div class="quick-party-error" data-error-for="state"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="pincode">
                        <label for="{{ $quickPartnerModalId }}Pincode">Pincode</label>
                        <input type="text" name="pincode" id="{{ $quickPartnerModalId }}Pincode" placeholder="Pincode">
                        <div class="quick-party-error" data-error-for="pincode"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-12" data-field="address">
                        <label for="{{ $quickPartnerModalId }}Address">Address</label>
                        <textarea name="address" id="{{ $quickPartnerModalId }}Address" rows="2" placeholder="Billing or operating address"></textarea>
                        <div class="quick-party-error" data-error-for="address"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-12" data-field="location">
                        <label for="{{ $quickPartnerModalId }}Location">Location</label>
                        <input type="text" name="location" id="{{ $quickPartnerModalId }}Location" placeholder="Map link or location reference">
                        <div class="quick-party-error" data-error-for="location"></div>
                    </div>
                </div>
            </div>
            <div class="quick-party-actions">
                <button type="button" class="quick-party-btn quick-party-btn-light" data-close-modal="button">Cancel</button>
                <button type="submit" class="quick-party-btn quick-party-btn-primary">Save Partner</button>
            </div>
        </form>
    </div>
</div>

<div class="quick-party-modal" id="{{ $quickClientModalId }}" hidden aria-hidden="true">
    <div class="quick-party-backdrop" data-close-modal="overlay"></div>
    <div class="quick-party-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickClientModalId }}Title">
        <div class="quick-party-header">
            <div>
                <h3 id="{{ $quickClientModalId }}Title">Add Actual Client</h3>
                <p>Create a delivery location/client in-line and keep this order moving.</p>
            </div>
            <button type="button" class="quick-party-close" data-close-modal="button" aria-label="Close">&times;</button>
        </div>

        <form id="{{ $quickClientFormId }}" method="POST" class="quick-party-form" novalidate data-route-template="{{ $quickClientRouteTemplate }}">
            @csrf
            <input type="hidden" name="business_partner_id" value="">
            <div class="quick-party-body">
                <div class="quick-party-alert" data-role="client-alert" hidden></div>
                <div class="quick-party-inline-note">
                    <strong style="display:block; color:#0f172a; margin-bottom:4px;">Business Partner</strong>
                    <span data-role="selected-partner-copy">Select a business partner first.</span>
                </div>
                <div class="quick-party-grid">
                    <div class="quick-party-field quick-party-col-6" data-field="client_name">
                        <label for="{{ $quickClientModalId }}ClientName">Client Name</label>
                        <input type="text" name="client_name" id="{{ $quickClientModalId }}ClientName" placeholder="Client or delivery contact" required>
                        <div class="quick-party-error" data-error-for="client_name"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-6" data-field="phone">
                        <label for="{{ $quickClientModalId }}Phone">Phone</label>
                        <input type="text" name="phone" id="{{ $quickClientModalId }}Phone" placeholder="Primary phone">
                        <div class="quick-party-error" data-error-for="phone"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-6" data-field="alternate_phone">
                        <label for="{{ $quickClientModalId }}AlternatePhone">Alternate Phone</label>
                        <input type="text" name="alternate_phone" id="{{ $quickClientModalId }}AlternatePhone" placeholder="Alternate phone">
                        <div class="quick-party-error" data-error-for="alternate_phone"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-6" data-field="location">
                        <label for="{{ $quickClientModalId }}Location">Map Location</label>
                        <input type="text" name="location" id="{{ $quickClientModalId }}Location" placeholder="Google Maps link or location note">
                        <div class="quick-party-error" data-error-for="location"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="city">
                        <label for="{{ $quickClientModalId }}City">City</label>
                        <input type="text" name="city" id="{{ $quickClientModalId }}City" placeholder="City">
                        <div class="quick-party-error" data-error-for="city"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="state">
                        <label for="{{ $quickClientModalId }}State">State</label>
                        <select name="state" id="{{ $quickClientModalId }}State">
                            <option value="">Select state</option>
                            @foreach($quickStateOptions as $state)
                                <option value="{{ $state }}">{{ $state }}</option>
                            @endforeach
                        </select>
                        <div class="quick-party-error" data-error-for="state"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-4" data-field="pincode">
                        <label for="{{ $quickClientModalId }}Pincode">Pincode</label>
                        <input type="text" name="pincode" id="{{ $quickClientModalId }}Pincode" placeholder="Pincode">
                        <div class="quick-party-error" data-error-for="pincode"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-12" data-field="address">
                        <label for="{{ $quickClientModalId }}Address">Address</label>
                        <textarea name="address" id="{{ $quickClientModalId }}Address" rows="2" placeholder="Delivery address"></textarea>
                        <div class="quick-party-error" data-error-for="address"></div>
                    </div>
                    <div class="quick-party-field quick-party-col-12" data-field="delivery_notes">
                        <label for="{{ $quickClientModalId }}DeliveryNotes">Delivery Notes</label>
                        <textarea name="delivery_notes" id="{{ $quickClientModalId }}DeliveryNotes" rows="3" placeholder="Gate instructions, landmark, timing, or access notes"></textarea>
                        <div class="quick-party-error" data-error-for="delivery_notes"></div>
                    </div>
                </div>
            </div>
            <div class="quick-party-actions">
                <button type="button" class="quick-party-btn quick-party-btn-light" data-close-modal="button">Cancel</button>
                <button type="submit" class="quick-party-btn quick-party-btn-primary">Save Client</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const partnerModal = document.getElementById(@json($quickPartnerModalId));
        const clientModal = document.getElementById(@json($quickClientModalId));

        if (!partnerModal || !clientModal || partnerModal.dataset.initialized === 'true') {
            return;
        }

        partnerModal.dataset.initialized = 'true';

        const partnerForm = document.getElementById(@json($quickPartnerFormId));
        const clientForm = document.getElementById(@json($quickClientFormId));
        const partnerSelectTarget = document.getElementById(@json($quickPartnerSelectTarget));
        const clientSelectTarget = document.getElementById(@json($quickClientSelectTarget));
        const clientPartnerSource = document.getElementById(@json($quickClientPartnerSource));
        const selectedPartnerCopy = clientForm.querySelector('[data-role="selected-partner-copy"]');
        const partnerAlert = partnerForm.querySelector('[data-role="partner-alert"]');
        const clientAlert = clientForm.querySelector('[data-role="client-alert"]');

        function setAlert(element, message, isError) {
            if (!element) {
                return;
            }

            if (!message) {
                element.hidden = true;
                element.textContent = '';
                element.classList.remove('is-error');
                return;
            }

            element.hidden = false;
            element.textContent = message;
            element.classList.toggle('is-error', !!isError);
        }

        function clearFieldErrors(form) {
            form.querySelectorAll('[data-field]').forEach(function (field) {
                field.classList.remove('is-error');
            });
            form.querySelectorAll('[data-error-for]').forEach(function (errorNode) {
                errorNode.textContent = '';
            });
        }

        function setFieldErrors(form, errors) {
            Object.keys(errors || {}).forEach(function (name) {
                const field = form.querySelector('[data-field="' + name + '"]');
                const errorNode = form.querySelector('[data-error-for="' + name + '"]');

                if (field) {
                    field.classList.add('is-error');
                }
                if (errorNode) {
                    errorNode.textContent = Array.isArray(errors[name]) ? errors[name][0] : String(errors[name]);
                }
            });
        }

        function openModal(modal) {
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();

            window.requestAnimationFrame(function () {
                const firstField = modal.querySelector('input, select, textarea');
                firstField?.focus({ preventScroll: true });
            });
        }

        function closeModal(modal) {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            window.rentnexisModalLock?.unlock();
        }

        function resetPartnerForm() {
            partnerForm.reset();
            clearFieldErrors(partnerForm);
            setAlert(partnerAlert, '', false);
        }

        function resetClientForm() {
            clientForm.reset();
            clearFieldErrors(clientForm);
            setAlert(clientAlert, '', false);
        }

        function activePartnerOption() {
            if (!clientPartnerSource) {
                return null;
            }

            const selected = clientPartnerSource.selectedOptions?.[0];
            if (!selected || !clientPartnerSource.value) {
                return null;
            }

            return selected;
        }

        document.querySelectorAll('[data-open-modal="{{ $quickPartnerModalId }}"]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                resetPartnerForm();
                openModal(partnerModal);
            });
        });

        document.querySelectorAll('[data-open-modal="{{ $quickClientModalId }}"]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                const partnerOption = activePartnerOption();
                if (!partnerOption) {
                    setAlert(clientAlert, 'Select a business partner first.', true);
                    return;
                }

                resetClientForm();
                clientForm.action = (clientForm.getAttribute('data-route-template') || '').replace('__PARTNER__', clientPartnerSource.value);
                clientForm.querySelector('input[name="business_partner_id"]').value = clientPartnerSource.value;
                selectedPartnerCopy.textContent = partnerOption.textContent.trim();
                openModal(clientModal);
            });
        });

        [partnerModal, clientModal].forEach(function (modal) {
            modal.querySelectorAll('[data-close-modal]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    closeModal(modal);
                });
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal || event.target.hasAttribute('data-close-modal')) {
                    closeModal(modal);
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (!partnerModal.hidden) {
                    closeModal(partnerModal);
                }
                if (!clientModal.hidden) {
                    closeModal(clientModal);
                }
            }
        });

        partnerForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearFieldErrors(partnerForm);
            setAlert(partnerAlert, '', false);

            fetch(partnerForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': partnerForm.querySelector('input[name="_token"]').value
                },
                body: new FormData(partnerForm)
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            throw payload;
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    window.dispatchEvent(new CustomEvent('business-partner:created', {
                        detail: payload.business_partner || null
                    }));
                    closeModal(partnerModal);
                    resetPartnerForm();
                })
                .catch(function (payload) {
                    setFieldErrors(partnerForm, payload?.errors || {});
                    setAlert(partnerAlert, payload?.message || 'Unable to save business partner.', true);
                });
        });

        clientForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearFieldErrors(clientForm);
            setAlert(clientAlert, '', false);

            fetch(clientForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': clientForm.querySelector('input[name="_token"]').value
                },
                body: new FormData(clientForm)
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            throw payload;
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    window.dispatchEvent(new CustomEvent('partner-client:created', {
                        detail: payload.partner_client || null
                    }));
                    closeModal(clientModal);
                    resetClientForm();
                })
                .catch(function (payload) {
                    setFieldErrors(clientForm, payload?.errors || {});
                    setAlert(clientAlert, payload?.message || 'Unable to save actual client.', true);
                });
        });
    })();
</script>
