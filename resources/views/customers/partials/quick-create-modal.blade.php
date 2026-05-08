@php
    $quickModalId = $quickModalId ?? 'quickCustomerModal';
    $quickFormId = $quickFormId ?? ($quickModalId . 'Form');
    $quickSelectTarget = $quickSelectTarget ?? 'customer_id';
    $quickRoute = $quickRoute ?? route('customers.quick-store');
    $quickStates = \App\Models\Customer::indianStates();
    $quickCountryCodes = \App\Support\PhoneNumber::countryCodeOptions();
    $quickCustomerTypes = ['Individual' => 'Individual', 'Business' => 'Business'];
    $quickSalutations = ['Mr.' => 'Mr.', 'Mrs.' => 'Mrs.', 'Ms.' => 'Ms.', 'Dr.' => 'Dr.'];
@endphp

@once
<style>
    .quick-customer-modal {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: grid;
        place-items: center;
        padding: 12px;
        overflow: hidden;
    }
    .quick-customer-modal[hidden] { display: none !important; }
    .quick-customer-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.52);
    }
    .quick-customer-dialog {
        position: relative;
        width: 100%;
        max-width: 600px;
        max-height: min(90vh, calc(100dvh - 24px));
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 20px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        overflow: hidden;
    }
    .quick-customer-dialog * { min-width: 0; }
    .quick-customer-header {
        flex: 0 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px 14px;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
        z-index: 2;
    }
    .quick-customer-header h3 { margin: 0; font-size: 18px; color: #0f172a; line-height: 1.25; }
    .quick-customer-header p { margin: 6px 0 0; color: #64748b; font-size: 13px; line-height: 1.5; }
    .quick-customer-close {
        flex: 0 0 auto;
        width: 44px;
        height: 44px;
        min-width: 44px;
        min-height: 44px;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #f8fafc;
        color: #334155;
        cursor: pointer;
        font-size: 24px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .quick-customer-form {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .quick-customer-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        padding: 14px 18px 18px;
        display: grid;
        gap: 12px;
    }
    .quick-customer-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; }
    .quick-col-4 { grid-column: span 4; }
    .quick-col-8 { grid-column: span 8; }
    .quick-col-12 { grid-column: span 12; }
    .quick-inline-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .quick-field { display: flex; flex-direction: column; gap: 6px; }
    .quick-field label { font-size: 11px; font-weight: 700; color: #475569; letter-spacing: .04em; text-transform: uppercase; }
    .quick-field input,
    .quick-field select,
    .quick-field textarea {
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
    .quick-field textarea { resize: vertical; min-height: 92px; }
    .quick-customer-actions {
        flex: 0 0 auto;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        padding: 14px 18px 16px;
        border-top: 1px solid #e2e8f0;
        background: #fff;
    }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 44px;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        border: 1px solid transparent;
        cursor: pointer;
    }
    .quick-btn-primary { background: #0f172a; color: #fff; }
    .quick-btn-light { background: #fff; color: #334155; border-color: #cbd5e1; }
    .quick-customer-alert {
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 12px;
        border: 1px solid transparent;
    }
    .quick-customer-alert.is-error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .quick-customer-alert.is-success { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
    @media (max-width: 640px) {
        .quick-customer-modal {
            padding: 8px;
            place-items: end center;
        }
        .quick-customer-dialog {
            max-width: 100%;
            max-height: min(92vh, calc(100dvh - 16px));
            border-radius: 18px;
        }
        .quick-customer-header,
        .quick-customer-body,
        .quick-customer-actions {
            padding-left: 14px;
            padding-right: 14px;
        }
        .quick-customer-header h3 { font-size: 17px; }
        .quick-customer-header p { font-size: 12px; }
        .quick-col-4,
        .quick-col-8 { grid-column: span 12; }
        .quick-inline-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 420px) {
        .quick-customer-actions {
            display: grid;
            grid-template-columns: 1fr;
        }
        .quick-btn { width: 100%; }
    }
</style>
@endonce

<div class="quick-customer-modal" id="{{ $quickModalId }}" hidden aria-hidden="true">
    <div class="quick-customer-backdrop" data-close-modal="overlay"></div>
    <div class="quick-customer-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickModalId }}Title">
        <div class="quick-customer-header">
            <div>
                <h3 id="{{ $quickModalId }}Title">Add Customer</h3>
                <p>Create a customer without leaving this form. The new record will be selected automatically.</p>
            </div>
            <button type="button" class="quick-customer-close" data-close-modal="button" aria-label="Close">&times;</button>
        </div>

        <form id="{{ $quickFormId }}" action="{{ $quickRoute }}" method="POST" class="quick-customer-form" novalidate>
            @csrf
            <input type="hidden" name="quick_create" value="1">

            <div class="quick-customer-body" data-role="quick-body">
                <div class="quick-customer-alert" data-role="quick-alert" hidden></div>

            <div class="quick-customer-grid">
                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}CustomerType">Customer Type</label>
                    <select name="customer_type" id="{{ $quickModalId }}CustomerType" data-role="customer-type" required>
                        @foreach($quickCustomerTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="quick-field quick-col-8 quick-type-panel" data-type-panel="Individual">
                    <div class="quick-inline-grid">
                        <div class="quick-field">
                            <label for="{{ $quickModalId }}Salutation">Salutation</label>
                            <select name="salutation" id="{{ $quickModalId }}Salutation">
                                <option value="">Select</option>
                                @foreach($quickSalutations as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="quick-field">
                            <label for="{{ $quickModalId }}FirstName">First Name</label>
                            <input type="text" name="first_name" id="{{ $quickModalId }}FirstName" placeholder="First name">
                        </div>
                        <div class="quick-field">
                            <label for="{{ $quickModalId }}LastName">Last Name</label>
                            <input type="text" name="last_name" id="{{ $quickModalId }}LastName" placeholder="Last name">
                        </div>
                    </div>
                </div>

                <div class="quick-field quick-col-8 quick-type-panel" data-type-panel="Business" hidden>
                    <div class="quick-inline-grid">
                        <div class="quick-field">
                            <label for="{{ $quickModalId }}CompanyName">Company Name</label>
                            <input type="text" name="company_name" id="{{ $quickModalId }}CompanyName" placeholder="Company name">
                        </div>
                        <div class="quick-field">
                            <label for="{{ $quickModalId }}ContactName">Contact Name</label>
                            <input type="text" name="contact_name" id="{{ $quickModalId }}ContactName" placeholder="Contact person">
                        </div>
                    </div>
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}Phone">Phone</label>
                    <div style="display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:10px; overflow:visible; background:#fff;">
                        @include('partials.country-code-picker', [
                            'name' => 'phone_country_code',
                            'pickerId' => $quickModalId . 'PhoneCountryCode',
                            'value' => \App\Support\PhoneNumber::DEFAULT_CODE,
                            'options' => $quickCountryCodes,
                            'dividerColor' => '#cbd5e1',
                            'width' => '92px',
                        ])
                        <input type="text" name="phone" id="{{ $quickModalId }}Phone" placeholder="Primary phone" required inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none; background:transparent;">
                    </div>
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}Whatsapp">WhatsApp</label>
                    <div style="display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:10px; overflow:visible; background:#fff;">
                        @include('partials.country-code-picker', [
                            'name' => 'whatsapp_number_country_code',
                            'pickerId' => $quickModalId . 'WhatsappCountryCode',
                            'value' => \App\Support\PhoneNumber::DEFAULT_CODE,
                            'options' => $quickCountryCodes,
                            'dividerColor' => '#cbd5e1',
                            'width' => '92px',
                        ])
                        <input type="text" name="whatsapp_number" id="{{ $quickModalId }}Whatsapp" placeholder="WhatsApp number" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none; background:transparent;">
                    </div>
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}Email">Email</label>
                    <input type="email" name="email" id="{{ $quickModalId }}Email" placeholder="Email address">
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}City">City</label>
                    <input type="text" name="city" id="{{ $quickModalId }}City" placeholder="City" required>
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}State">State</label>
                    <select name="state" id="{{ $quickModalId }}State" required>
                        <option value="">Select state</option>
                        @foreach($quickStates as $state)
                            <option value="{{ $state }}">{{ $state }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="quick-field quick-col-4">
                    <label for="{{ $quickModalId }}Pincode">Pincode</label>
                    <input type="text" name="pincode" id="{{ $quickModalId }}Pincode" placeholder="Pincode">
                </div>

                <div class="quick-field quick-col-12">
                    <label for="{{ $quickModalId }}Address">Address</label>
                    <textarea name="address" id="{{ $quickModalId }}Address" rows="2" placeholder="Address or landmark"></textarea>
                </div>
            </div>
            </div>

            <div class="quick-customer-actions">
                <button type="button" class="quick-btn quick-btn-light" data-close-modal="button">Cancel</button>
                <button type="submit" class="quick-btn quick-btn-primary">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById(@json($quickModalId));

        if (!modal || modal.dataset.initialized === 'true') {
            return;
        }

        modal.dataset.initialized = 'true';

        const form = document.getElementById(@json($quickFormId));
        const targetSelect = document.getElementById(@json($quickSelectTarget));
        const typeSelect = form.querySelector('[data-role="customer-type"]');
        const panels = form.querySelectorAll('[data-type-panel]');
        const alertBox = form.querySelector('[data-role="quick-alert"]');
        const bodyScroll = form.querySelector('[data-role="quick-body"]');
        const openButtons = document.querySelectorAll('[data-open-modal="{{ $quickModalId }}"]');
        const closeButtons = modal.querySelectorAll('[data-close-modal]');
        const firstFocusableField = form.querySelector('input, select, textarea');

        function setAlert(message, isError) {
            if (!message) {
                alertBox.hidden = true;
                alertBox.textContent = '';
                alertBox.classList.remove('is-error', 'is-success');
                return;
            }

            alertBox.hidden = false;
            alertBox.textContent = message;
            alertBox.classList.toggle('is-error', !!isError);
            alertBox.classList.toggle('is-success', !isError);
        }

        function syncTypePanels() {
            const currentType = typeSelect.value || 'Individual';

            panels.forEach(function (panel) {
                const visible = panel.getAttribute('data-type-panel') === currentType;
                panel.hidden = !visible;
            });
        }

        function resetCustomerModal() {
            form.reset();
            typeSelect.value = 'Individual';
            syncTypePanels();
            setAlert('', false);
        }

        function openCustomerModal() {
            resetCustomerModal();
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();

            window.requestAnimationFrame(function () {
                if (firstFocusableField) {
                    firstFocusableField.focus({ preventScroll: true });
                    window.rentnexisModalScrollFieldIntoView?.(firstFocusableField, bodyScroll);
                }
            });
        }

        function closeCustomerModal() {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            window.rentnexisModalLock?.unlock();
            setAlert('', false);
        }

        function appendAndSelectCustomer(customer) {
            if (!targetSelect || !customer) {
                return;
            }

            const countryCode = customer.phone_country_code || '+91';
            const phoneDigits = String(customer.phone || '').replace(/\D+/g, '');
            const codeDigits = String(countryCode).replace(/\D+/g, '');
            const localPhone = phoneDigits.startsWith(codeDigits)
                ? phoneDigits.slice(codeDigits.length)
                : phoneDigits;

            const existingOption = targetSelect.querySelector('option[value="' + customer.id + '"]');
            const option = existingOption || document.createElement('option');

            option.value = customer.id;
            option.textContent = customer.phone ? customer.name + ' - ' + customer.phone : customer.name;
            option.setAttribute('data-name', customer.name || '');
            option.setAttribute('data-phone', localPhone || '');
            option.setAttribute('data-phone-country', countryCode);

            if (!existingOption) {
                targetSelect.appendChild(option);
            }

            targetSelect.value = String(customer.id);
            targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        openButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openCustomerModal();
            });
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                closeCustomerModal();
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.hasAttribute('data-close-modal')) {
                closeCustomerModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeCustomerModal();
            }
        });

        modal.addEventListener('focusin', function (event) {
            const target = event.target;

            if (target instanceof HTMLElement && target.matches('input, select, textarea')) {
                window.rentnexisModalScrollFieldIntoView?.(target, bodyScroll);
            }
        });

        typeSelect.addEventListener('change', syncTypePanels);
        syncTypePanels();

        form.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            setAlert('', false);

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value
                },
                body: formData
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
                    appendAndSelectCustomer(payload.customer || null);
                    resetCustomerModal();
                    closeCustomerModal();
                })
                .catch(function (payload) {
                    const message = payload && payload.message
                        ? payload.message
                        : 'Unable to save customer. Please check the required fields.';
                    setAlert(message, true);
                });
        });
    })();
</script>
