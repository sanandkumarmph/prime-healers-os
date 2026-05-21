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
    const testSoundButton = root.querySelector('[data-notification-test-sound]');
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
    const notificationAudio = typeof Audio !== 'undefined' && soundSrc ? new Audio(soundSrc) : null;
    let audioUnlocked = window.localStorage.getItem(audioUnlockKey) === '1';
    let soundEnabled = root.dataset.notificationSoundEnabled === 'true';
    let voiceEnabled = root.dataset.notificationVoiceEnabled === 'true';
    let preferenceSaveHandle = null;

    root.querySelectorAll('[data-notification-id]').forEach((item) => {
        const id = item.getAttribute('data-notification-id');
        if (id) {
            seenIds.add(id);
        }
    });
    persistSeenIds(seenIds);

    let hydrated = false;
    let pollingHandle = null;

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

    const persistPreferences = () => {
        if (!preferencesUrl) {
            return;
        }

        if (preferenceSaveHandle) {
            window.clearTimeout(preferenceSaveHandle);
        }

        preferenceSaveHandle = window.setTimeout(async () => {
            try {
                await window.axios.post(preferencesUrl, {
                    sound_alerts_enabled: soundEnabled,
                    voice_alerts_enabled: voiceEnabled,
                });
            } catch (error) {
                setHint('Could not save alert settings right now.', true);
            }
        }, 180);
    };

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

    const speakNotification = (notification) => {
        if (!voiceEnabled || typeof window.speechSynthesis === 'undefined') {
            return;
        }

        const label = safeVoiceLabels[String(notification.type || '')] || 'New operational update';
        try {
            window.speechSynthesis.cancel();
            const utterance = new window.SpeechSynthesisUtterance(label);
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            window.speechSynthesis.speak(utterance);
        } catch (error) {
            // Speech support is best-effort only.
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
            return;
        }

        try {
            await window.axios.post(readUrlTemplate.replace('__NOTIFICATION__', notificationId));
        } catch (error) {
            // Navigation should still proceed even if read state fails.
        }
    };

    root.addEventListener('click', async (event) => {
        const markAll = event.target.closest('[data-notification-mark-all]');
        if (markAll) {
            event.preventDefault();
            if (!readAllUrl) {
                return;
            }
            try {
                await window.axios.post(readAllUrl);
                root.querySelectorAll('[data-notification-item]').forEach((item) => item.classList.remove('is-unread'));
                root.querySelectorAll('.topbar-bell-count').forEach((chip) => chip.remove());
                syncCountUi(0);
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

        await markRead(notificationId);

        if (notificationId) {
            seenIds.add(notificationId);
            persistSeenIds(seenIds);
        }

        if (href !== '#') {
            window.location.assign(href);
        }
    });

    if (markAllButton instanceof HTMLElement && !readAllUrl) {
        markAllButton.hidden = true;
    }

    if (soundToggle instanceof HTMLInputElement) {
        soundToggle.checked = soundEnabled;
        soundToggle.addEventListener('change', async () => {
            soundEnabled = soundToggle.checked;
            root.dataset.notificationSoundEnabled = soundEnabled ? 'true' : 'false';
            persistPreferences();
            if (soundEnabled) {
                await unlockAudio();
            }
        });
    }

    if (voiceToggle instanceof HTMLInputElement) {
        voiceToggle.checked = voiceEnabled;
        voiceToggle.addEventListener('change', () => {
            voiceEnabled = voiceToggle.checked;
            root.dataset.notificationVoiceEnabled = voiceEnabled ? 'true' : 'false';
            persistPreferences();
        });
    }

    if (testSoundButton instanceof HTMLButtonElement) {
        testSoundButton.addEventListener('click', async () => {
            await playNotificationSound({ test: true });
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

document.addEventListener('DOMContentLoaded', initializeInAppNotifications);
