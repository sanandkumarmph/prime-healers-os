@php
    $followUpFeatureReady = $followUpFeatureReady ?? \Illuminate\Support\Facades\Schema::hasTable('follow_ups');
    $followUpAssignableUsers = $followUpAssignableUsers
        ?? \App\Models\User::query()
            ->where('organization_id', auth()->user()->organization_id)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    $followUpModalId = $followUpModalId ?? 'global-follow-up-modal';
    $followUpAction = $followUpAction ?? route('communication-center.store');
@endphp

@once
    <style>
        .ph-followup-modal[hidden] { display:none; }
        .ph-followup-modal {
            position:fixed;
            inset:0;
            z-index:1400;
            background:rgba(15,23,42,.48);
            display:flex;
            align-items:center;
            justify-content:center;
            padding:18px;
        }
        .ph-followup-dialog {
            width:min(100%, 640px);
            max-height:calc(100vh - 36px);
            overflow:auto;
            border-radius:22px;
            border:1px solid #dbe3ef;
            background:#fff;
            box-shadow:0 28px 64px rgba(15,23,42,.24);
        }
        .ph-followup-head,
        .ph-followup-foot {
            position:sticky;
            z-index:2;
            background:#fff;
        }
        .ph-followup-head {
            top:0;
            padding:18px 18px 14px;
            border-bottom:1px solid #eef2f7;
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:flex-start;
        }
        .ph-followup-head h3 {
            margin:0;
            font-size:18px;
            color:#0f172a;
        }
        .ph-followup-head p {
            margin:6px 0 0;
            color:#64748b;
            font-size:12px;
            line-height:1.5;
        }
        .ph-followup-body {
            padding:18px;
            display:grid;
            gap:14px;
        }
        .ph-followup-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:12px;
        }
        .ph-followup-field {
            display:grid;
            gap:6px;
        }
        .ph-followup-field label {
            color:#334155;
            font-size:12px;
            font-weight:800;
        }
        .ph-followup-input,
        .ph-followup-select,
        .ph-followup-textarea {
            width:100%;
            min-height:44px;
            box-sizing:border-box;
            border:1px solid #dbe3ef;
            border-radius:14px;
            background:#fff;
            color:#0f172a;
            padding:10px 12px;
            font-size:14px;
        }
        .ph-followup-textarea {
            min-height:112px;
            resize:vertical;
        }
        .ph-followup-context {
            display:grid;
            gap:6px;
            padding:12px 14px;
            border-radius:16px;
            border:1px solid #e5edf7;
            background:#f8fafc;
        }
        .ph-followup-context span {
            color:#64748b;
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.05em;
        }
        .ph-followup-context strong {
            color:#0f172a;
            font-size:13px;
            line-height:1.55;
            overflow-wrap:anywhere;
        }
        .ph-followup-foot {
            bottom:0;
            padding:14px 18px 18px;
            border-top:1px solid #eef2f7;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }
        .ph-followup-btn,
        .ph-followup-btn-soft,
        .ph-followup-btn-ghost {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            min-height:42px;
            padding:10px 14px;
            border-radius:14px;
            border:1px solid transparent;
            font-size:13px;
            font-weight:800;
            cursor:pointer;
            font-family:inherit;
            text-decoration:none;
        }
        .ph-followup-btn { background:#2563eb; color:#fff; }
        .ph-followup-btn-soft { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
        .ph-followup-btn-ghost { background:#fff; color:#334155; border-color:#dbe3ef; }
        .ph-followup-close {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:36px;
            height:36px;
            border-radius:999px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#475569;
            cursor:pointer;
        }
        .ph-followup-error {
            color:#b91c1c;
            font-size:12px;
            line-height:1.5;
        }
        @media (max-width: 640px) {
            .ph-followup-modal {
                padding:0;
                align-items:stretch;
            }
            .ph-followup-dialog {
                width:100%;
                max-height:100vh;
                border-radius:0;
            }
            .ph-followup-grid {
                grid-template-columns:1fr;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.querySelector('[data-followup-modal]');
            if (!modal) return;

            const form = modal.querySelector('form');
            const contextPreview = modal.querySelector('[data-followup-context-preview]');
            const typeField = modal.querySelector('[name="followup_type"]');
            const dueField = modal.querySelector('[name="due_at"]');
            const noteField = modal.querySelector('[name="note"]');
            const priorityField = modal.querySelector('[name="priority"]');
            const assignedUserField = modal.querySelector('[name="assigned_user_id"]');
            const hiddenFields = ['customer_id', 'business_partner_id', 'partner_client_id', 'rental_id', 'sale_id', 'invoice_id', 'delivery_id'];
            const now = new Date();
            const defaultDue = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

            const resetForm = () => {
                form.reset();
                if (dueField && !dueField.value) {
                    dueField.value = defaultDue;
                }
                hiddenFields.forEach((field) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input) input.value = '';
                });
                if (contextPreview) {
                    contextPreview.hidden = true;
                    contextPreview.innerHTML = '';
                }
            };

            const renderContext = (context) => {
                if (!contextPreview) return;

                const lines = [];

                if (context.reference) {
                    lines.push(`<div><span>Reference</span><strong>${context.reference}</strong></div>`);
                }
                if (context.reminder_contact) {
                    lines.push(`<div><span>Reminder / Payment</span><strong>${context.reminder_contact}</strong></div>`);
                }
                if (context.service_contact) {
                    lines.push(`<div><span>Service Contact</span><strong>${context.service_contact}</strong></div>`);
                }
                if (context.service_address) {
                    lines.push(`<div><span>Location</span><strong>${context.service_address}</strong></div>`);
                }

                contextPreview.innerHTML = lines.join('');
                contextPreview.hidden = lines.length === 0;
            };

            const openModal = (button) => {
                resetForm();

                let context = {};
                try {
                    context = JSON.parse(button.dataset.followUpContext || '{}');
                } catch (error) {
                    context = {};
                }

                hiddenFields.forEach((field) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input && context[field] !== undefined && context[field] !== null) {
                        input.value = context[field];
                    }
                });

                if (typeField && button.dataset.followUpType) {
                    typeField.value = button.dataset.followUpType;
                }
                if (priorityField && button.dataset.followUpPriority) {
                    priorityField.value = button.dataset.followUpPriority;
                }
                if (assignedUserField && button.dataset.followUpAssignedUser) {
                    assignedUserField.value = button.dataset.followUpAssignedUser;
                }
                if (noteField && button.dataset.followUpNote) {
                    noteField.value = button.dataset.followUpNote;
                }
                if (dueField && button.dataset.followUpDueAt) {
                    dueField.value = button.dataset.followUpDueAt;
                }

                renderContext(context);

                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            };

            const closeModal = () => {
                modal.hidden = true;
                document.body.style.overflow = '';
            };

            document.querySelectorAll('[data-open-follow-up-modal]').forEach((button) => {
                button.addEventListener('click', () => openModal(button));
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });

            modal.querySelectorAll('[data-close-follow-up-modal]').forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });

            @if($errors->has('followup_type') || $errors->has('due_at') || $errors->has('priority') || $errors->has('note'))
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            @endif
        });
    </script>
@endonce

@if($followUpFeatureReady)
    <div class="ph-followup-modal" data-followup-modal hidden>
        <div class="ph-followup-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $followUpModalId }}-title">
            <div class="ph-followup-head">
                <div>
                    <h3 id="{{ $followUpModalId }}-title">Add Follow-up</h3>
                    <p>Capture the next communication step without leaving the current workflow.</p>
                </div>
                <button type="button" class="ph-followup-close" data-close-follow-up-modal>&times;</button>
            </div>
            <form method="POST" action="{{ $followUpAction }}">
                @csrf
                <div class="ph-followup-body">
                    <div class="ph-followup-context" data-followup-context-preview hidden></div>

                    <input type="hidden" name="customer_id">
                    <input type="hidden" name="business_partner_id">
                    <input type="hidden" name="partner_client_id">
                    <input type="hidden" name="rental_id">
                    <input type="hidden" name="sale_id">
                    <input type="hidden" name="invoice_id">
                    <input type="hidden" name="delivery_id">

                    <div class="ph-followup-grid">
                        <div class="ph-followup-field">
                            <label for="{{ $followUpModalId }}-type">Follow-up Type</label>
                            <select id="{{ $followUpModalId }}-type" class="ph-followup-select" name="followup_type">
                                @foreach(\App\Models\FollowUp::TYPES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('followup_type') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ph-followup-field">
                            <label for="{{ $followUpModalId }}-due">Due Date & Time</label>
                            <input id="{{ $followUpModalId }}-due" class="ph-followup-input" type="datetime-local" name="due_at" value="{{ old('due_at', now()->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>

                    <div class="ph-followup-grid">
                        <div class="ph-followup-field">
                            <label for="{{ $followUpModalId }}-assigned-user">Assigned Staff</label>
                            <select id="{{ $followUpModalId }}-assigned-user" class="ph-followup-select" name="assigned_user_id">
                                <option value="">Unassigned</option>
                                @foreach($followUpAssignableUsers as $user)
                                    <option value="{{ $user->id }}" @selected((string) old('assigned_user_id') === (string) $user->id)>{{ $user->name }} ({{ \Illuminate\Support\Str::headline($user->role ?: 'user') }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ph-followup-field">
                            <label for="{{ $followUpModalId }}-priority">Priority</label>
                            <select id="{{ $followUpModalId }}-priority" class="ph-followup-select" name="priority">
                                @foreach(\App\Models\FollowUp::PRIORITIES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', \App\Models\FollowUp::PRIORITY_MEDIUM) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="ph-followup-field">
                        <label for="{{ $followUpModalId }}-note">Note</label>
                        <textarea id="{{ $followUpModalId }}-note" class="ph-followup-textarea" name="note" placeholder="What needs to happen next, what was discussed, or what the next staff member should know.">{{ old('note') }}</textarea>
                    </div>

                    @if($errors->has('followup_type') || $errors->has('due_at') || $errors->has('priority') || $errors->has('note'))
                        <div class="ph-followup-error">
                            @foreach(['followup_type', 'due_at', 'priority', 'note'] as $field)
                                @foreach($errors->get($field) as $message)
                                    <div>{{ $message }}</div>
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="ph-followup-foot">
                    <button type="button" class="ph-followup-btn-ghost" data-close-follow-up-modal>Cancel</button>
                    <button type="submit" class="ph-followup-btn">Save Follow-up</button>
                </div>
            </form>
        </div>
    </div>
@endif
