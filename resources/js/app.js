import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

const shouldAutoSelectNumberInput = (element) => {
    if (!(element instanceof HTMLInputElement)) {
        return false;
    }

    if (element.type !== 'number') {
        return false;
    }

    if (element.disabled || element.readOnly) {
        return false;
    }

    if (element.dataset.autoSelectNumber === 'off') {
        return false;
    }

    if (element.hasAttribute('data-no-auto-select')) {
        return false;
    }

    if (element.classList.contains('no-auto-select')) {
        return false;
    }

    return true;
};

const selectNumberInputValue = (element) => {
    if (!shouldAutoSelectNumberInput(element)) {
        return;
    }

    window.requestAnimationFrame(() => {
        try {
            element.select();
        } catch (error) {
            // Ignore browsers that do not support selecting this input type.
        }
    });
};

document.addEventListener('focusin', (event) => {
    selectNumberInputValue(event.target);
});

document.addEventListener('click', (event) => {
    if (!shouldAutoSelectNumberInput(event.target)) {
        return;
    }

    if (document.activeElement !== event.target) {
        return;
    }

    selectNumberInputValue(event.target);
});

const initializeInAppNotifications = () => {
    const root = document.querySelector('[data-in-app-notifications]');

    if (!(root instanceof HTMLElement)) {
        return;
    }

    const latestUrl = root.dataset.notificationsLatestUrl;
    const readAllUrl = root.dataset.notificationsReadAllUrl;
    const readVisibleUrl = root.dataset.notificationsReadVisibleUrl;
    const readUrlTemplate = root.dataset.notificationsReadUrlTemplate;
    const preferencesUrl = root.dataset.notificationsPreferencesUrl;
    const soundSrc = root.dataset.notificationSoundSrc;
    const panel = root.querySelector('.topbar-bell-panel');
    const badge = root.querySelector('[data-notification-badge]');
    const headCount = root.querySelector('[data-notification-head-count]');
    const subtitle = root.querySelector('[data-notification-subtitle]');
    const emptyState = root.querySelector('[data-notification-empty]');
    const markAllButton = root.querySelector('[data-notification-mark-all]');
    const toastStack = document.querySelector('[data-notification-toast-stack]');
    const soundToggle = root.querySelector('[data-notification-sound-toggle]');
    const voiceToggle = root.querySelector('[data-notification-voice-toggle]');
    const soundVariantSelect = root.querySelector('[data-notification-sound-variant]');
    const testSoundButton = root.querySelector('[data-notification-test-sound]');
    const testVoiceButton = root.querySelector('[data-notification-test-voice]');
    const settingsHint = root.querySelector('[data-notification-settings-hint]');
    const storageKey = 'ph_seen_notifications';
    const audioUnlockKey = 'ph_notification_audio_unlocked';
    const actionableAudioTypes = new Set([
        'delivery_assigned',
        'pickup_assigned',
        'followup_assigned',
        'pickup_failed',
        'delivery_failed',
        'pickup_completed',
        'delivery_completed',
    ]);
    const safeVoiceLabels = {
        delivery_assigned: 'New delivery assigned',
        pickup_assigned: 'New pickup assigned',
        followup_assigned: 'New follow-up assigned',
        pickup_failed: 'Pickup failed attempt reported',
        delivery_failed: 'Delivery failed attempt reported',
        pickup_completed: 'Pickup completed',
        delivery_completed: 'Delivery completed',
        payment_recorded: 'Payment recorded',
    };

    if (!(panel instanceof HTMLElement) || !latestUrl) {
        return;
    }

    const loadSeenIds = () => {
        try {
            const parsed = JSON.parse(window.sessionStorage.getItem(storageKey) || '[]');
            return Array.isArray(parsed) ? new Set(parsed.filter((id) => typeof id === 'string')) : new Set();
        } catch (error) {
            return new Set();
        }
    };

    const persistSeenIds = (ids) => {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(Array.from(ids).slice(-80)));
        } catch (error) {
            // Session storage is optional.
        }
    };

    const seenIds = loadSeenIds();
    let notificationAudio = typeof Audio !== 'undefined' && soundSrc ? new Audio(soundSrc) : null;
    let audioUnlocked = window.localStorage.getItem(audioUnlockKey) === '1';
    let soundEnabled = root.dataset.notificationSoundEnabled === 'true';
    let voiceEnabled = root.dataset.notificationVoiceEnabled === 'true';
    let soundVariant = root.dataset.notificationSoundVariant || 'default';

    root.querySelectorAll('[data-notification-id]').forEach((item) => {
        const id = item.getAttribute('data-notification-id');
        if (id) {
            seenIds.add(id);
        }
    });
    persistSeenIds(seenIds);

    let hydrated = false;
    let pollingHandle = null;
    let markVisibleHandle = null;
    let isMarkingVisible = false;

    const setHint = (message, persistent = false) => {
        if (!(settingsHint instanceof HTMLElement)) {
            return;
        }

        if (!message) {
            settingsHint.hidden = true;
            settingsHint.textContent = '';
            return;
        }

        settingsHint.textContent = message;
        settingsHint.hidden = false;

        if (!persistent) {
            window.setTimeout(() => {
                if (settingsHint.textContent === message) {
                    settingsHint.hidden = true;
                }
            }, 3600);
        }
    };

    const updateAudioSource = (variant) => {
        if (!soundSrc) {
            return;
        }

        const normalizedVariant = ['default', 'soft', 'chime'].includes(String(variant || '').toLowerCase())
            ? String(variant).toLowerCase()
            : 'default';

        const nextSrc = normalizedVariant === 'default'
            ? soundSrc.replace(/notification-[a-z0-9_-]+\.wav$/i, 'notification.wav')
            : soundSrc.replace(/notification-[a-z0-9_-]+\.wav$/i, `notification-${normalizedVariant}.wav`)
                .replace(/notification\.wav$/i, `notification-${normalizedVariant}.wav`);

        root.dataset.notificationSoundVariant = normalizedVariant;

        if (notificationAudio) {
            notificationAudio.src = nextSrc;
            notificationAudio.load();
            return;
        }

        if (typeof Audio !== 'undefined') {
            notificationAudio = new Audio(nextSrc);
        }
    };

    const syncPreferenceControls = () => {
        if (soundToggle instanceof HTMLInputElement) {
            soundToggle.checked = soundEnabled;
        }

        if (voiceToggle instanceof HTMLInputElement) {
            voiceToggle.checked = voiceEnabled;
        }

        if (soundVariantSelect instanceof HTMLSelectElement) {
            soundVariantSelect.value = soundVariant;
        }
    };

    const setPreferenceControlsDisabled = (disabled) => {
        [soundToggle, voiceToggle, soundVariantSelect, testSoundButton, testVoiceButton].forEach((element) => {
            if (element instanceof HTMLElement) {
                element.toggleAttribute('disabled', disabled);
            }
        });
    };

    const persistPreferences = async (nextState) => {
        if (!preferencesUrl) {
            return true;
        }

        setPreferenceControlsDisabled(true);

        try {
            const response = await window.axios.post(preferencesUrl, {
                sound_enabled: nextState.soundEnabled,
                voice_enabled: nextState.voiceEnabled,
                sound_variant: nextState.soundVariant,
            });

            soundEnabled = Boolean(response.data?.sound_enabled ?? response.data?.sound_alerts_enabled);
            voiceEnabled = Boolean(response.data?.voice_enabled ?? response.data?.voice_alerts_enabled);
            soundVariant = String(response.data?.sound_variant || nextState.soundVariant || 'default');
            root.dataset.notificationSoundEnabled = soundEnabled ? 'true' : 'false';
            root.dataset.notificationVoiceEnabled = voiceEnabled ? 'true' : 'false';
            updateAudioSource(soundVariant);
            syncPreferenceControls();
            setHint('Saved');
            return true;
        } catch (error) {
            syncPreferenceControls();
            setHint('Unable to save preference right now.', true);
            return false;
        } finally {
            setPreferenceControlsDisabled(false);
        }
    };

    updateAudioSource(soundVariant);

    const unlockAudio = async () => {
        if (!notificationAudio) {
            return false;
        }

        if (audioUnlocked) {
            return true;
        }

        try {
            notificationAudio.currentTime = 0;
            notificationAudio.volume = 0;
            await notificationAudio.play();
            notificationAudio.pause();
            notificationAudio.currentTime = 0;
            notificationAudio.volume = 1;
            audioUnlocked = true;
            window.localStorage.setItem(audioUnlockKey, '1');
            setHint('');
            return true;
        } catch (error) {
            setHint('Click Test Sound to allow notification sound in this browser.', true);
            return false;
        }
    };

    const playNotificationSound = async ({ test = false } = {}) => {
        if (!notificationAudio) {
            return;
        }

        if (!test && !soundEnabled) {
            return;
        }

        const unlocked = await unlockAudio();
        if (!unlocked) {
            return;
        }

        try {
            notificationAudio.volume = 1;
            notificationAudio.currentTime = 0;
            await notificationAudio.play();
        } catch (error) {
            setHint('Sound playback is blocked until you interact with the page.', true);
        }
    };

    const speakNotification = (notification, { test = false } = {}) => {
        if ((!voiceEnabled && !test) || typeof window.speechSynthesis === 'undefined') {
            if (test && typeof window.speechSynthesis === 'undefined') {
                setHint('Voice alerts are not supported in this browser.', true);
            }
            return;
        }

        const label = test
            ? 'New task assigned'
            : (safeVoiceLabels[String(notification.type || '')] || 'New operational update');
        try {
            window.speechSynthesis.cancel();
            const utterance = new window.SpeechSynthesisUtterance(label);
            utterance.lang = 'en-IN';
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            window.speechSynthesis.speak(utterance);
        } catch (error) {
            if (test) {
                setHint('Voice test could not start in this browser.', true);
            }
        }
    };

    const getList = () => {
        let list = root.querySelector('[data-notification-list]');

        if (!list) {
            list = document.createElement('div');
            list.className = 'topbar-bell-list';
            list.setAttribute('data-notification-list', 'true');
            if (emptyState?.parentNode === panel) {
                panel.insertBefore(list, emptyState);
            } else {
                panel.appendChild(list);
            }
        }

        return list;
    };

    const formatBadgeCount = (count) => (count > 99 ? '99+' : String(count));

    const syncCountUi = (count) => {
        if (count > 0) {
            if (!(badge instanceof HTMLElement)) {
                const badgeElement = document.createElement('span');
                badgeElement.className = 'topbar-bell-badge';
                badgeElement.setAttribute('data-notification-badge', 'true');
                badgeElement.textContent = formatBadgeCount(count);
                const summary = root.querySelector('.topbar-bell-trigger');
                summary?.appendChild(badgeElement);
            } else {
                badge.textContent = formatBadgeCount(count);
                badge.hidden = false;
            }

            if (headCount instanceof HTMLElement) {
                headCount.textContent = String(count);
                headCount.hidden = false;
            } else {
                const countChip = document.createElement('span');
                countChip.className = 'rn-badge rn-badge-warning';
                countChip.setAttribute('data-notification-head-count', 'true');
                countChip.textContent = String(count);
                root.querySelector('.topbar-bell-head-actions')?.appendChild(countChip);
            }

            if (subtitle instanceof HTMLElement) {
                subtitle.textContent = 'Live operational updates for you';
            }
            if (markAllButton instanceof HTMLElement) {
                markAllButton.hidden = false;
            }
        } else {
            const liveBadge = root.querySelector('[data-notification-badge]');
            if (liveBadge instanceof HTMLElement) {
                liveBadge.hidden = true;
            }
            const liveHeadCount = root.querySelector('[data-notification-head-count]');
            if (liveHeadCount instanceof HTMLElement) {
                liveHeadCount.hidden = true;
            }
            if (subtitle instanceof HTMLElement) {
                subtitle.textContent = 'No unread notifications right now';
            }
            if (markAllButton instanceof HTMLElement) {
                markAllButton.hidden = true;
            }
        }
    };

    const markItemReadInDom = (notificationId) => {
        if (!notificationId) {
            return;
        }

        root.querySelectorAll(`[data-notification-id="${notificationId}"]`).forEach((item) => {
            item.classList.remove('is-unread');
            item.querySelectorAll('.topbar-bell-count').forEach((chip) => chip.remove());
        });
    };

    const visibleUnreadIds = () => Array.from(root.querySelectorAll('[data-notification-item].is-unread[data-notification-id]'))
        .map((item) => item.getAttribute('data-notification-id') || '')
        .filter((id) => id !== '');

    const markVisibleNotificationsRead = async () => {
        if (!readVisibleUrl || isMarkingVisible) {
            return;
        }

        const notificationIds = visibleUnreadIds();
        if (!notificationIds.length) {
            return;
        }

        isMarkingVisible = true;

        try {
            const response = await window.axios.post(readVisibleUrl, {
                notification_ids: notificationIds,
            });

            notificationIds.forEach((notificationId) => {
                markItemReadInDom(notificationId);
                seenIds.add(notificationId);
            });

            persistSeenIds(seenIds);
            syncCountUi(Number(response.data?.unread_count || 0));
        } catch (error) {
            // Keep current unread state on failure.
        } finally {
            isMarkingVisible = false;
        }
    };

    const buildNotificationItem = (notification) => {
        const href = notification.action_url || '#';
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.className = `topbar-bell-item${notification.is_unread ? ' is-unread' : ''}`;
        anchor.setAttribute('data-notification-item', 'true');
        anchor.setAttribute('data-notification-id', notification.id);
        anchor.setAttribute('data-notification-priority', notification.priority || 'medium');

        const body = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = notification.title || 'Operational update';
        const small = document.createElement('small');
        small.textContent = notification.message || 'Open for details.';

        const meta = document.createElement('div');
        meta.className = 'topbar-bell-meta';

        const priority = document.createElement('span');
        const priorityValue = String(notification.priority || 'medium').toLowerCase();
        priority.className = `topbar-bell-priority${priorityValue === 'medium' ? ' is-medium' : (priorityValue === 'high' || priorityValue === 'urgent' ? ' is-high' : '')}`;
        priority.textContent = priorityValue.toUpperCase();

        const time = document.createElement('span');
        time.className = 'topbar-bell-time';
        time.textContent = notification.time_ago || '';

        meta.append(priority, time);
        body.append(strong, small, meta);
        anchor.appendChild(body);

        if (notification.is_unread) {
            const count = document.createElement('span');
            count.className = 'topbar-bell-count';
            count.textContent = 'New';
            anchor.appendChild(count);
        }

        return anchor;
    };

    const renderNotifications = (notifications, unreadCount) => {
        const list = getList();
        list.innerHTML = '';

        if (notifications.length === 0) {
            list.hidden = true;
            if (emptyState instanceof HTMLElement) {
                emptyState.hidden = false;
            }
        } else {
            notifications.forEach((notification) => list.appendChild(buildNotificationItem(notification)));
            list.hidden = false;
            if (emptyState instanceof HTMLElement) {
                emptyState.hidden = true;
            }
        }

        syncCountUi(unreadCount);
    };

    const showToast = (notification) => {
        if (!(toastStack instanceof HTMLElement)) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'topbar-toast';

        const title = document.createElement('strong');
        title.textContent = notification.title || 'New notification';
        const body = document.createElement('p');
        body.textContent = notification.message || 'Open for details.';

        toast.append(title, body);

        if (notification.action_url) {
            const action = document.createElement('a');
            action.href = notification.action_url;
            action.textContent = 'View task';
            toast.appendChild(action);
        }

        toastStack.prepend(toast);
        window.setTimeout(() => {
            toast.remove();
        }, 5200);
    };

    const hydrate = async () => {
        try {
            const response = await window.axios.get(latestUrl);
            const notifications = Array.isArray(response.data?.notifications) ? response.data.notifications : [];
            const unreadCount = Number(response.data?.unread_count || 0);

            if (hydrated) {
                notifications
                    .filter((notification) => notification.is_unread && typeof notification.id === 'string' && !seenIds.has(notification.id))
                    .forEach((notification) => {
                        seenIds.add(notification.id);
                        showToast(notification);
                        if (actionableAudioTypes.has(String(notification.type || ''))) {
                            playNotificationSound();
                            speakNotification(notification);
                        }
                    });
                persistSeenIds(seenIds);
            } else {
                notifications.forEach((notification) => {
                    if (typeof notification.id === 'string') {
                        seenIds.add(notification.id);
                    }
                });
                persistSeenIds(seenIds);
            }

            renderNotifications(notifications, unreadCount);
            hydrated = true;
        } catch (error) {
            // Keep the last rendered notifications if polling fails.
        }
    };

    const markRead = async (notificationId) => {
        if (!readUrlTemplate || !notificationId) {
            return null;
        }

        try {
            const response = await window.axios.post(readUrlTemplate.replace('__NOTIFICATION__', notificationId));
            return response.data || null;
        } catch (error) {
            // Navigation should still proceed even if read state fails.
            return null;
        }
    };

    root.addEventListener('toggle', (event) => {
        if (event.target !== root) {
            return;
        }

        if (markVisibleHandle) {
            window.clearTimeout(markVisibleHandle);
            markVisibleHandle = null;
        }

        if (root.open) {
            markVisibleHandle = window.setTimeout(() => {
                markVisibleNotificationsRead();
            }, 900);
        }
    });

    root.addEventListener('click', async (event) => {
        const markAll = event.target.closest('[data-notification-mark-all]');
        if (markAll) {
            event.preventDefault();
            if (!readAllUrl) {
                return;
            }
            try {
                const response = await window.axios.post(readAllUrl);
                root.querySelectorAll('[data-notification-item]').forEach((item) => item.classList.remove('is-unread'));
                root.querySelectorAll('.topbar-bell-count').forEach((chip) => chip.remove());
                root.querySelectorAll('[data-notification-id]').forEach((item) => {
                    const id = item.getAttribute('data-notification-id');
                    if (id) {
                        seenIds.add(id);
                    }
                });
                persistSeenIds(seenIds);
                syncCountUi(Number(response.data?.unread_count || 0));
                if (emptyState instanceof HTMLElement && !(root.querySelector('[data-notification-item]'))) {
                    emptyState.hidden = false;
                }
            } catch (error) {
                // Keep current state on failure.
            }
            return;
        }

        const item = event.target.closest('[data-notification-item]');
        if (!item) {
            return;
        }

        const href = item.getAttribute('href') || '#';
        const notificationId = item.getAttribute('data-notification-id') || '';

        if (href === '#') {
            event.preventDefault();
        }

        const response = await markRead(notificationId);

        if (notificationId) {
            seenIds.add(notificationId);
            persistSeenIds(seenIds);
            markItemReadInDom(notificationId);
        }

        if (response && typeof response.unread_count !== 'undefined') {
            syncCountUi(Number(response.unread_count || 0));
        }

        if (href !== '#') {
            window.location.assign(href);
        }
    });

    if (markAllButton instanceof HTMLElement && !readAllUrl) {
        markAllButton.hidden = true;
    }

    syncPreferenceControls();

    if (soundToggle instanceof HTMLInputElement) {
        soundToggle.addEventListener('change', async () => {
            const previousSoundEnabled = soundEnabled;
            soundEnabled = soundToggle.checked;
            if (soundEnabled) {
                await unlockAudio();
            }
            const saved = await persistPreferences({
                soundEnabled,
                voiceEnabled,
                soundVariant,
            });

            if (!saved) {
                soundEnabled = previousSoundEnabled;
                root.dataset.notificationSoundEnabled = soundEnabled ? 'true' : 'false';
                syncPreferenceControls();
            }
        });
    }

    if (voiceToggle instanceof HTMLInputElement) {
        voiceToggle.addEventListener('change', async () => {
            const previousVoiceEnabled = voiceEnabled;
            voiceEnabled = voiceToggle.checked;
            const saved = await persistPreferences({
                soundEnabled,
                voiceEnabled,
                soundVariant,
            });

            if (!saved) {
                voiceEnabled = previousVoiceEnabled;
                root.dataset.notificationVoiceEnabled = voiceEnabled ? 'true' : 'false';
                syncPreferenceControls();
            }
        });
    }

    if (soundVariantSelect instanceof HTMLSelectElement) {
        soundVariantSelect.addEventListener('change', async () => {
            const previousSoundVariant = soundVariant;
            soundVariant = soundVariantSelect.value || 'default';
            updateAudioSource(soundVariant);
            const saved = await persistPreferences({
                soundEnabled,
                voiceEnabled,
                soundVariant,
            });

            if (!saved) {
                soundVariant = previousSoundVariant;
                updateAudioSource(soundVariant);
                syncPreferenceControls();
            }
        });
    }

    if (testSoundButton instanceof HTMLButtonElement) {
        testSoundButton.addEventListener('click', async () => {
            setHint('');
            await playNotificationSound({ test: true });
        });
    }

    if (testVoiceButton instanceof HTMLButtonElement) {
        testVoiceButton.addEventListener('click', () => {
            setHint('');
            speakNotification({ type: 'test' }, { test: true });
        });
    }

    hydrate();
    pollingHandle = window.setInterval(hydrate, 30000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            hydrate();
        }
    });

    window.addEventListener('beforeunload', () => {
        if (pollingHandle) {
            window.clearInterval(pollingHandle);
        }
    });
};

const initializeUnifiedInAppNotifications = () => {
    const roots = Array.from(document.querySelectorAll('[data-in-app-notifications]'))
        .filter((root) => root instanceof HTMLElement);

    if (!roots.length) {
        return;
    }

    const primaryRoot = roots.find((root) => root.dataset.notificationsLatestUrl) || roots[0];
    const latestUrl = primaryRoot.dataset.notificationsLatestUrl;
    const readAllUrl = primaryRoot.dataset.notificationsReadAllUrl;
    const readVisibleUrl = primaryRoot.dataset.notificationsReadVisibleUrl;
    const readUrlTemplate = primaryRoot.dataset.notificationsReadUrlTemplate;
    const preferencesUrl = primaryRoot.dataset.notificationsPreferencesUrl;
    const soundSrc = primaryRoot.dataset.notificationSoundSrc;
    const toastStack = document.querySelector('[data-notification-toast-stack]');
    const storageKey = 'ph_seen_notifications';
    const audioUnlockKey = 'ph_notification_audio_unlocked';
    const actionableAudioTypes = new Set([
        'delivery_assigned',
        'pickup_assigned',
        'followup_assigned',
        'pickup_failed',
        'delivery_failed',
        'pickup_completed',
        'delivery_completed',
    ]);
    const safeVoiceLabels = {
        delivery_assigned: 'New delivery assigned',
        pickup_assigned: 'New pickup assigned',
        followup_assigned: 'New follow-up assigned',
        pickup_failed: 'Pickup failed attempt reported',
        delivery_failed: 'Delivery failed attempt reported',
        pickup_completed: 'Pickup completed',
        delivery_completed: 'Delivery completed',
        payment_recorded: 'Payment recorded',
    };

    if (!latestUrl) {
        return;
    }

    const rootStates = roots.map((root) => ({
        root,
        panel: root.querySelector('.topbar-bell-panel'),
        subtitle: root.querySelector('[data-notification-subtitle]'),
        settingsHints: Array.from(root.querySelectorAll('[data-notification-settings-hint]')),
        markAllButtons: Array.from(root.querySelectorAll('[data-notification-mark-all]')),
        closeButtons: Array.from(root.querySelectorAll('[data-notification-close]')),
        backdrops: Array.from(root.querySelectorAll('[data-notification-backdrop]')),
        soundToggles: Array.from(root.querySelectorAll('[data-notification-sound-toggle]')),
        voiceToggles: Array.from(root.querySelectorAll('[data-notification-voice-toggle]')),
        soundVariantSelects: Array.from(root.querySelectorAll('[data-notification-sound-variant]')),
        testSoundButtons: Array.from(root.querySelectorAll('[data-notification-test-sound]')),
        testVoiceButtons: Array.from(root.querySelectorAll('[data-notification-test-voice]')),
        emptyStates: Array.from(root.querySelectorAll('[data-notification-empty]')),
        isMobileSheet: root.classList.contains('mobile-notification-menu'),
        markVisibleHandle: null,
        isMarkingVisible: false,
    })).filter((state) => state.panel instanceof HTMLElement);

    if (!rootStates.length) {
        return;
    }

    const loadSeenIds = () => {
        try {
            const parsed = JSON.parse(window.sessionStorage.getItem(storageKey) || '[]');
            return Array.isArray(parsed) ? new Set(parsed.filter((id) => typeof id === 'string')) : new Set();
        } catch (error) {
            return new Set();
        }
    };

    const persistSeenIds = (ids) => {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(Array.from(ids).slice(-80)));
        } catch (error) {
            // Session storage is optional.
        }
    };

    const seenIds = loadSeenIds();
    rootStates.forEach((state) => {
        state.root.querySelectorAll('[data-notification-id]').forEach((item) => {
            const id = item.getAttribute('data-notification-id');
            if (id) {
                seenIds.add(id);
            }
        });
    });
    persistSeenIds(seenIds);

    let notificationAudio = typeof Audio !== 'undefined' && soundSrc ? new Audio(soundSrc) : null;
    let audioUnlocked = window.localStorage.getItem(audioUnlockKey) === '1';
    let soundEnabled = primaryRoot.dataset.notificationSoundEnabled === 'true';
    let voiceEnabled = primaryRoot.dataset.notificationVoiceEnabled === 'true';
    let soundVariant = primaryRoot.dataset.notificationSoundVariant || 'default';
    let hydrated = false;
    let pollingHandle = null;
    let mobileSheetOpenCount = 0;

    const setMobileSheetLock = (isOpen) => {
        const bodyLock = window.rentnexisModalLock;

        if (isOpen) {
            mobileSheetOpenCount += 1;
        } else {
            mobileSheetOpenCount = Math.max(mobileSheetOpenCount - 1, 0);
        }

        if (bodyLock && typeof bodyLock.lock === 'function' && typeof bodyLock.unlock === 'function') {
            if (isOpen) {
                bodyLock.lock();
            } else if (mobileSheetOpenCount === 0) {
                bodyLock.unlock();
            }

            return;
        }

        if (mobileSheetOpenCount > 0) {
            document.body.style.overflow = 'hidden';
            return;
        }

        document.body.style.overflow = '';
    };

    const closeNotificationRoot = (state) => {
        if (!state?.root?.open) {
            return;
        }

        if (state.markVisibleHandle) {
            window.clearTimeout(state.markVisibleHandle);
            state.markVisibleHandle = null;
        }

        state.root.open = false;
        state.root.removeAttribute('open');

        if (state.isMobileSheet) {
            setMobileSheetLock(false);
        }
    };

    const setHint = (message, persistent = false, targetState = null) => {
        const states = targetState ? [targetState] : rootStates;

        states.forEach((state) => {
            state.settingsHints.forEach((hint) => {
                if (!(hint instanceof HTMLElement)) {
                    return;
                }

                if (!message) {
                    hint.hidden = true;
                    hint.textContent = '';
                    return;
                }

                hint.textContent = message;
                hint.hidden = false;

                if (!persistent) {
                    window.setTimeout(() => {
                        if (hint.textContent === message) {
                            hint.hidden = true;
                        }
                    }, 3600);
                }
            });
        });
    };

    const updateAudioSource = (variant) => {
        if (!soundSrc) {
            return;
        }

        const normalizedVariant = ['default', 'soft', 'chime'].includes(String(variant || '').toLowerCase())
            ? String(variant).toLowerCase()
            : 'default';

        const nextSrc = normalizedVariant === 'default'
            ? soundSrc.replace(/notification-[a-z0-9_-]+\.wav$/i, 'notification.wav')
            : soundSrc.replace(/notification-[a-z0-9_-]+\.wav$/i, `notification-${normalizedVariant}.wav`)
                .replace(/notification\.wav$/i, `notification-${normalizedVariant}.wav`);

        rootStates.forEach((state) => {
            state.root.dataset.notificationSoundVariant = normalizedVariant;
        });

        if (notificationAudio) {
            notificationAudio.src = nextSrc;
            notificationAudio.load();
        } else if (typeof Audio !== 'undefined') {
            notificationAudio = new Audio(nextSrc);
        }
    };

    const syncPreferenceControls = () => {
        rootStates.forEach((state) => {
            state.soundToggles.forEach((toggle) => {
                if (toggle instanceof HTMLInputElement) {
                    toggle.checked = soundEnabled;
                }
            });

            state.voiceToggles.forEach((toggle) => {
                if (toggle instanceof HTMLInputElement) {
                    toggle.checked = voiceEnabled;
                }
            });

            state.soundVariantSelects.forEach((select) => {
                if (select instanceof HTMLSelectElement) {
                    select.value = soundVariant;
                }
            });
        });
    };

    const setPreferenceControlsDisabled = (disabled) => {
        rootStates.forEach((state) => {
            [
                ...state.soundToggles,
                ...state.voiceToggles,
                ...state.soundVariantSelects,
                ...state.testSoundButtons,
                ...state.testVoiceButtons,
            ].forEach((element) => {
                if (element instanceof HTMLElement) {
                    element.toggleAttribute('disabled', disabled);
                }
            });
        });
    };

    const persistPreferences = async (nextState) => {
        if (!preferencesUrl) {
            return true;
        }

        setPreferenceControlsDisabled(true);

        try {
            const response = await window.axios.post(preferencesUrl, {
                sound_enabled: nextState.soundEnabled,
                voice_enabled: nextState.voiceEnabled,
                sound_variant: nextState.soundVariant,
            });

            soundEnabled = Boolean(response.data?.sound_enabled ?? response.data?.sound_alerts_enabled);
            voiceEnabled = Boolean(response.data?.voice_enabled ?? response.data?.voice_alerts_enabled);
            soundVariant = String(response.data?.sound_variant || nextState.soundVariant || 'default');

            rootStates.forEach((state) => {
                state.root.dataset.notificationSoundEnabled = soundEnabled ? 'true' : 'false';
                state.root.dataset.notificationVoiceEnabled = voiceEnabled ? 'true' : 'false';
            });

            updateAudioSource(soundVariant);
            syncPreferenceControls();
            setHint('Saved');
            return true;
        } catch (error) {
            syncPreferenceControls();
            setHint('Unable to save preference right now.', true);
            return false;
        } finally {
            setPreferenceControlsDisabled(false);
        }
    };

    updateAudioSource(soundVariant);

    const unlockAudio = async () => {
        if (!notificationAudio) {
            return false;
        }

        if (audioUnlocked) {
            return true;
        }

        try {
            notificationAudio.currentTime = 0;
            notificationAudio.volume = 0;
            await notificationAudio.play();
            notificationAudio.pause();
            notificationAudio.currentTime = 0;
            notificationAudio.volume = 1;
            audioUnlocked = true;
            window.localStorage.setItem(audioUnlockKey, '1');
            setHint('');
            return true;
        } catch (error) {
            setHint('Tap Test Sound once to allow browser audio.', true);
            return false;
        }
    };

    const playNotificationSound = async ({ test = false } = {}) => {
        if (!notificationAudio) {
            return;
        }

        if (!test && !soundEnabled) {
            return;
        }

        const unlocked = await unlockAudio();
        if (!unlocked) {
            return;
        }

        try {
            notificationAudio.volume = 1;
            notificationAudio.currentTime = 0;
            await notificationAudio.play();
        } catch (error) {
            setHint('Sound playback is blocked until you interact with the page.', true);
        }
    };

    const speakNotification = (notification, { test = false } = {}) => {
        if ((!voiceEnabled && !test) || typeof window.speechSynthesis === 'undefined') {
            if (test && typeof window.speechSynthesis === 'undefined') {
                setHint('Voice alerts are not supported in this browser.', true);
            }
            return;
        }

        const label = test
            ? 'New task assigned'
            : (safeVoiceLabels[String(notification.type || '')] || 'New operational update');

        try {
            window.speechSynthesis.cancel();
            const utterance = new window.SpeechSynthesisUtterance(label);
            utterance.lang = 'en-IN';
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            window.speechSynthesis.speak(utterance);
        } catch (error) {
            if (test) {
                setHint('Voice test could not start in this browser.', true);
            }
        }
    };

    const getList = (state) => {
        let list = state.root.querySelector('[data-notification-list]');

        if (!list) {
            list = document.createElement('div');
            list.className = 'topbar-bell-list';
            list.setAttribute('data-notification-list', 'true');
            const [firstEmptyState] = state.emptyStates;
            if (firstEmptyState?.parentNode === state.panel) {
                state.panel.insertBefore(list, firstEmptyState);
            } else {
                state.panel.appendChild(list);
            }
        }

        return list;
    };

    const formatBadgeCount = (count) => (count > 99 ? '99+' : String(count));

    const syncCountUi = (count) => {
        rootStates.forEach((state) => {
            const liveBadge = state.root.querySelector('[data-notification-badge]');
            const liveHeadCount = state.root.querySelector('[data-notification-head-count]');

            if (count > 0) {
                if (!(liveBadge instanceof HTMLElement)) {
                    const badgeElement = document.createElement('span');
                    badgeElement.className = 'topbar-bell-badge';
                    badgeElement.setAttribute('data-notification-badge', 'true');
                    badgeElement.textContent = formatBadgeCount(count);
                    state.root.querySelector('.topbar-bell-trigger')?.appendChild(badgeElement);
                } else {
                    liveBadge.textContent = formatBadgeCount(count);
                    liveBadge.hidden = false;
                }

                if (liveHeadCount instanceof HTMLElement) {
                    liveHeadCount.textContent = String(count);
                    liveHeadCount.hidden = false;
                } else {
                    const countChip = document.createElement('span');
                    countChip.className = 'rn-badge rn-badge-warning';
                    countChip.setAttribute('data-notification-head-count', 'true');
                    countChip.textContent = String(count);
                    state.root.querySelector('.topbar-bell-head-actions')?.appendChild(countChip);
                }

                if (state.subtitle instanceof HTMLElement) {
                    state.subtitle.textContent = 'Live operational updates for you';
                }

                state.markAllButtons.forEach((button) => {
                    if (button instanceof HTMLElement) {
                        button.hidden = false;
                    }
                });

                return;
            }

            if (liveBadge instanceof HTMLElement) {
                liveBadge.hidden = true;
            }

            if (liveHeadCount instanceof HTMLElement) {
                liveHeadCount.hidden = true;
            }

            if (state.subtitle instanceof HTMLElement) {
                state.subtitle.textContent = 'No unread notifications right now';
            }

            state.markAllButtons.forEach((button) => {
                if (button instanceof HTMLElement) {
                    button.hidden = true;
                }
            });
        });
    };

    const markItemReadInDom = (notificationId) => {
        if (!notificationId) {
            return;
        }

        rootStates.forEach((state) => {
            state.root.querySelectorAll(`[data-notification-id="${notificationId}"]`).forEach((item) => {
                item.classList.remove('is-unread');
                item.querySelectorAll('.topbar-bell-count').forEach((chip) => chip.remove());
            });
        });
    };

    const visibleUnreadIds = (state) => Array.from(state.root.querySelectorAll('[data-notification-item].is-unread[data-notification-id]'))
        .map((item) => item.getAttribute('data-notification-id') || '')
        .filter((id) => id !== '');

    const markVisibleNotificationsRead = async (state) => {
        if (!readVisibleUrl || state.isMarkingVisible) {
            return;
        }

        const notificationIds = visibleUnreadIds(state);
        if (!notificationIds.length) {
            return;
        }

        state.isMarkingVisible = true;

        try {
            const response = await window.axios.post(readVisibleUrl, {
                notification_ids: notificationIds,
            });

            notificationIds.forEach((notificationId) => {
                markItemReadInDom(notificationId);
                seenIds.add(notificationId);
            });

            persistSeenIds(seenIds);
            syncCountUi(Number(response.data?.unread_count || 0));
        } catch (error) {
            // Keep current unread state on failure.
        } finally {
            state.isMarkingVisible = false;
        }
    };

    const buildNotificationItem = (notification) => {
        const href = notification.action_url || '#';
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.className = `topbar-bell-item${notification.is_unread ? ' is-unread' : ''}`;
        anchor.setAttribute('data-notification-item', 'true');
        anchor.setAttribute('data-notification-id', notification.id);
        anchor.setAttribute('data-notification-priority', notification.priority || 'medium');

        const body = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = notification.title || 'Operational update';
        const small = document.createElement('small');
        small.textContent = notification.message || 'Open for details.';

        const meta = document.createElement('div');
        meta.className = 'topbar-bell-meta';

        const priority = document.createElement('span');
        const priorityValue = String(notification.priority || 'medium').toLowerCase();
        priority.className = `topbar-bell-priority${priorityValue === 'medium' ? ' is-medium' : (priorityValue === 'high' || priorityValue === 'urgent' ? ' is-high' : '')}`;
        priority.textContent = priorityValue.toUpperCase();

        const time = document.createElement('span');
        time.className = 'topbar-bell-time';
        time.textContent = notification.time_ago || '';

        meta.append(priority, time);
        body.append(strong, small, meta);
        anchor.appendChild(body);

        if (notification.is_unread) {
            const count = document.createElement('span');
            count.className = 'topbar-bell-count';
            count.textContent = 'New';
            anchor.appendChild(count);
        }

        return anchor;
    };

    const renderNotifications = (notifications, unreadCount) => {
        rootStates.forEach((state) => {
            const list = getList(state);
            list.innerHTML = '';

            if (notifications.length === 0) {
                list.hidden = true;
                state.emptyStates.forEach((emptyState) => {
                    if (emptyState instanceof HTMLElement) {
                        emptyState.hidden = false;
                    }
                });
            } else {
                notifications.forEach((notification) => list.appendChild(buildNotificationItem(notification)));
                list.hidden = false;
                state.emptyStates.forEach((emptyState) => {
                    if (emptyState instanceof HTMLElement) {
                        emptyState.hidden = true;
                    }
                });
            }
        });

        syncCountUi(unreadCount);
    };

    const showToast = (notification) => {
        if (!(toastStack instanceof HTMLElement)) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'topbar-toast';

        const title = document.createElement('strong');
        title.textContent = notification.title || 'New notification';
        const body = document.createElement('p');
        body.textContent = notification.message || 'Open for details.';

        toast.append(title, body);

        if (notification.action_url) {
            const action = document.createElement('a');
            action.href = notification.action_url;
            action.textContent = 'View task';
            toast.appendChild(action);
        }

        toastStack.prepend(toast);
        window.setTimeout(() => {
            toast.remove();
        }, 5200);
    };

    const hydrate = async () => {
        try {
            const response = await window.axios.get(latestUrl);
            const notifications = Array.isArray(response.data?.notifications) ? response.data.notifications : [];
            const unreadCount = Number(response.data?.unread_count || 0);

            if (hydrated) {
                notifications
                    .filter((notification) => notification.is_unread && typeof notification.id === 'string' && !seenIds.has(notification.id))
                    .forEach((notification) => {
                        seenIds.add(notification.id);
                        showToast(notification);
                        if (actionableAudioTypes.has(String(notification.type || ''))) {
                            playNotificationSound();
                            speakNotification(notification);
                        }
                    });
                persistSeenIds(seenIds);
            } else {
                notifications.forEach((notification) => {
                    if (typeof notification.id === 'string') {
                        seenIds.add(notification.id);
                    }
                });
                persistSeenIds(seenIds);
            }

            renderNotifications(notifications, unreadCount);
            hydrated = true;
        } catch (error) {
            // Keep the last rendered notifications if polling fails.
        }
    };

    const markRead = async (notificationId) => {
        if (!readUrlTemplate || !notificationId) {
            return null;
        }

        try {
            const response = await window.axios.post(readUrlTemplate.replace('__NOTIFICATION__', notificationId));
            return response.data || null;
        } catch (error) {
            return null;
        }
    };

    syncPreferenceControls();

    rootStates.forEach((state) => {
        const bindCloseHandler = (element) => {
            if (!(element instanceof HTMLElement)) {
                return;
            }

            element.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeNotificationRoot(state);
            });
        };

        state.closeButtons.forEach(bindCloseHandler);
        state.backdrops.forEach(bindCloseHandler);

        state.root.addEventListener('toggle', (event) => {
            if (event.target !== state.root) {
                return;
            }

            if (state.isMobileSheet) {
                setMobileSheetLock(state.root.open);
            }

            if (state.markVisibleHandle) {
                window.clearTimeout(state.markVisibleHandle);
                state.markVisibleHandle = null;
            }

            if (state.root.open) {
                state.markVisibleHandle = window.setTimeout(() => {
                    markVisibleNotificationsRead(state);
                }, 900);
            }
        });

        state.root.addEventListener('click', async (event) => {
            const markAll = event.target.closest('[data-notification-mark-all]');
            if (markAll) {
                event.preventDefault();
                if (!readAllUrl) {
                    return;
                }

                try {
                    const response = await window.axios.post(readAllUrl);
                    rootStates.forEach((innerState) => {
                        innerState.root.querySelectorAll('[data-notification-item]').forEach((item) => item.classList.remove('is-unread'));
                        innerState.root.querySelectorAll('.topbar-bell-count').forEach((chip) => chip.remove());
                        innerState.root.querySelectorAll('[data-notification-id]').forEach((item) => {
                            const id = item.getAttribute('data-notification-id');
                            if (id) {
                                seenIds.add(id);
                            }
                        });
                    });
                    persistSeenIds(seenIds);
                    syncCountUi(Number(response.data?.unread_count || 0));
                } catch (error) {
                    // Keep current state on failure.
                }

                return;
            }

            const item = event.target.closest('[data-notification-item]');
            if (!item) {
                return;
            }

            const href = item.getAttribute('href') || '#';
            const notificationId = item.getAttribute('data-notification-id') || '';

            if (href === '#') {
                event.preventDefault();
            }

            const response = await markRead(notificationId);

            if (notificationId) {
                seenIds.add(notificationId);
                persistSeenIds(seenIds);
                markItemReadInDom(notificationId);
            }

            if (response && typeof response.unread_count !== 'undefined') {
                syncCountUi(Number(response.unread_count || 0));
            }

            if (href !== '#') {
                closeNotificationRoot(state);
                window.location.assign(href);
            }
        });

        state.markAllButtons.forEach((button) => {
            if (button instanceof HTMLElement && !readAllUrl) {
                button.hidden = true;
            }
        });

        state.soundToggles.forEach((toggle) => {
            if (!(toggle instanceof HTMLInputElement)) {
                return;
            }

            toggle.addEventListener('change', async () => {
                const previousSoundEnabled = soundEnabled;
                soundEnabled = toggle.checked;

                if (soundEnabled) {
                    await unlockAudio();
                }

                const saved = await persistPreferences({
                    soundEnabled,
                    voiceEnabled,
                    soundVariant,
                });

                if (!saved) {
                    soundEnabled = previousSoundEnabled;
                    rootStates.forEach((innerState) => {
                        innerState.root.dataset.notificationSoundEnabled = soundEnabled ? 'true' : 'false';
                    });
                    syncPreferenceControls();
                }
            });
        });

        state.voiceToggles.forEach((toggle) => {
            if (!(toggle instanceof HTMLInputElement)) {
                return;
            }

            toggle.addEventListener('change', async () => {
                const previousVoiceEnabled = voiceEnabled;
                voiceEnabled = toggle.checked;
                const saved = await persistPreferences({
                    soundEnabled,
                    voiceEnabled,
                    soundVariant,
                });

                if (!saved) {
                    voiceEnabled = previousVoiceEnabled;
                    rootStates.forEach((innerState) => {
                        innerState.root.dataset.notificationVoiceEnabled = voiceEnabled ? 'true' : 'false';
                    });
                    syncPreferenceControls();
                }
            });
        });

        state.soundVariantSelects.forEach((select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            select.addEventListener('change', async () => {
                const previousSoundVariant = soundVariant;
                soundVariant = select.value || 'default';
                updateAudioSource(soundVariant);
                const saved = await persistPreferences({
                    soundEnabled,
                    voiceEnabled,
                    soundVariant,
                });

                if (!saved) {
                    soundVariant = previousSoundVariant;
                    updateAudioSource(soundVariant);
                    syncPreferenceControls();
                }
            });
        });

        state.testSoundButtons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.addEventListener('click', async () => {
                setHint('', false, state);
                await playNotificationSound({ test: true });
            });
        });

        state.testVoiceButtons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.addEventListener('click', () => {
                setHint('', false, state);
                speakNotification({ type: 'test' }, { test: true });
            });
        });
    });

    hydrate();
    pollingHandle = window.setInterval(hydrate, 30000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            hydrate();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        rootStates.forEach((state) => {
            if (state.root.open) {
                closeNotificationRoot(state);
            }
        });
    });

    window.addEventListener('beforeunload', () => {
        if (pollingHandle) {
            window.clearInterval(pollingHandle);
        }

        if (mobileSheetOpenCount > 0) {
            document.body.style.overflow = '';
        }
    });
};

document.addEventListener('DOMContentLoaded', initializeUnifiedInAppNotifications);

const initializeMobileUxStandardization = () => {
    const mobileQuery = window.matchMedia('(max-width: 767px)');

    const filterSelector = [
        'details[data-filter-panel]',
        'details.reports-filter',
        'details.invoice-filter-toggle',
        'details.desktop-filter-toggle',
        'details.ops-filter-toggle',
        'details.product-filter-card',
        'details.asset-card[data-filter-panel]',
    ].join(', ');

    const closeMobileFilters = () => {
        if (!mobileQuery.matches) {
            return;
        }

        document.querySelectorAll(filterSelector).forEach((panel) => {
            if (!(panel instanceof HTMLDetailsElement)) {
                return;
            }

            if (panel.dataset.mobileKeepOpen === 'true') {
                return;
            }

            panel.open = false;
            panel.setAttribute('data-mobile-standardized', 'true');
        });
    };

    const markMobileStandardizedRoots = () => {
        if (!mobileQuery.matches) {
            return;
        }

        document.querySelectorAll('.mobile-filter-sheet, .quick-customer-modal, .quick-party-modal, .delivery-edit-modal').forEach((root) => {
            if (root instanceof HTMLElement) {
                root.setAttribute('data-mobile-standardized', 'true');
            }
        });
    };

    closeMobileFilters();
    window.setTimeout(closeMobileFilters, 0);
    window.setTimeout(closeMobileFilters, 180);
    markMobileStandardizedRoots();

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', (event) => {
            if (event.matches) {
                closeMobileFilters();
                markMobileStandardizedRoots();
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', initializeMobileUxStandardization);
const initializeGlobalOutsideDismiss = () => {
    const menuSelector = [
        'details.rcc-action-more',
        'details.customer-action-menu',
        'details.ops-action-menu',
        'details.delivery-action-menu',
        'details.product-action-menu',
        'details.asset-action-menu',
        'details.invoice-action-menu',
        'details.mobile-actions-menu',
        'details.quick-add-menu',
        'details.topbar-notification-menu',
        'details.topbar-user-menu',
        'details[data-rental-mobile-menu]',
        'details[data-dropdown-menu]',
        'details[data-action-menu]',
    ].join(', ');

    const excludedMenuSelector = [
        '.rcc-accordion-section',
        '.rcc-details-panel',
        '.rcc-details',
        '[data-filter-panel]',
        '.reports-filter',
        '.invoice-filter-toggle',
        '.desktop-filter-toggle',
        '.ops-filter-toggle',
        '.product-filter-card',
        '.asset-card[data-filter-panel]',
        '.ph-rental-mobile-accordion',
    ].join(', ');

    const getManagedMenus = () => Array.from(document.querySelectorAll(menuSelector)).filter((menu) => {
        return menu instanceof HTMLDetailsElement && !menu.matches(excludedMenuSelector);
    });

    const closeManagedMenus = (except = null) => {
        getManagedMenus().forEach((menu) => {
            if (menu === except || !menu.open) {
                return;
            }

            menu.open = false;
            menu.removeAttribute('open');
            menu.closest('tr')?.classList.remove('is-action-open');
        });
    };

    const closeModalFromBackdropClick = (event) => {
        const target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        const modalSelector = [
            '.renewal-modal.is-open',
            '.delivery-edit-modal.is-open',
            '.quick-customer-modal.is-open',
            '.quick-party-modal.is-open',
            '[data-modal].is-open',
            '[data-modal].open',
        ].join(', ');

        if (!target.matches(modalSelector)) {
            return;
        }

        const closeButton = target.querySelector([
            '[data-close-renewal-modal]',
            '[data-close-pickup-modal]',
            '[data-delivery-edit-close]',
            '[data-modal-close]',
            '[data-close-modal]',
            '.modal-close',
            '[aria-label="Close"]',
        ].join(', '));

        if (closeButton instanceof HTMLElement) {
            closeButton.click();
            return;
        }

        target.classList.remove('is-open', 'open');
        target.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    document.addEventListener('toggle', (event) => {
        const menu = event.target;

        if (!(menu instanceof HTMLDetailsElement) || !menu.matches(menuSelector) || menu.matches(excludedMenuSelector)) {
            return;
        }

        if (menu.open) {
            closeManagedMenus(menu);
        }
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        closeModalFromBackdropClick(event);

        if (!target.closest(menuSelector)) {
            closeManagedMenus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeManagedMenus();
        }
    });
};

document.addEventListener('DOMContentLoaded', initializeGlobalOutsideDismiss);
