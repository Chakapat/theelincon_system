/**
 * CRUD audio feedback ทั้งระบบ (โหลดจาก navbar)
 * Web Audio API for most sounds; delete uses assets/audio/trash-delete.mp3.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'tnc_system_audio_muted';
    var STORAGE_KEY_LEGACY = 'tnc_pr_po_audio_muted';
    var AUDIO_KINDS = ['create', 'update', 'approve', 'complete', 'delete'];
    var ctx = null;
    var masterGain = null;
    var trashAudio = null;
    var pendingKind = null;
    var playedOnLoad = false;
    var lastAjaxAudioAt = 0;

    function isMuted() {
        if (window.TncSoundSettings && typeof window.TncSoundSettings.isMuted === 'function') {
            return window.TncSoundSettings.isMuted();
        }
        try {
            return localStorage.getItem(STORAGE_KEY) === '1'
                || localStorage.getItem(STORAGE_KEY_LEGACY) === '1'
                || localStorage.getItem('tnc_notif_audio_muted') === '1';
        } catch (e) {
            return false;
        }
    }

    function setMuted(muted) {
        if (window.TncSoundSettings && typeof window.TncSoundSettings.setMuted === 'function') {
            window.TncSoundSettings.setMuted(muted);
            return;
        }
        try {
            if (muted) {
                localStorage.setItem(STORAGE_KEY, '1');
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
            localStorage.removeItem(STORAGE_KEY_LEGACY);
            localStorage.removeItem('tnc_notif_audio_muted');
        } catch (e) {}
    }

    function prefersReduced() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function normalizeKind(kind) {
        var k = String(kind || '').trim();
        return AUDIO_KINDS.indexOf(k) !== -1 ? k : null;
    }

    function ensureCtx() {
        if (!ctx) {
            try {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (AC) {
                    ctx = new AC();
                    masterGain = ctx.createGain();
                    masterGain.gain.value = 0.92;
                    masterGain.connect(ctx.destination);
                }
            } catch (e) {
                ctx = null;
                masterGain = null;
            }
        }
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(function () {});
        }
        return ctx;
    }

    function getDest() {
        ensureCtx();
        return masterGain || (ctx && ctx.destination);
    }

    /**
     * @param {{ freq: number, start?: number, dur?: number, vol?: number, type?: OscillatorType, glideTo?: number, glideSec?: number, detune?: number }} opts
     */
    function playNote(opts) {
        var c = ensureCtx();
        var dest = getDest();
        if (!c || !dest) {
            return;
        }
        try {
            var t = c.currentTime + (opts.start || 0);
            var dur = opts.dur || 0.14;
            var vol = opts.vol || 0.18;
            var osc = c.createOscillator();
            var gain = c.createGain();
            osc.type = opts.type || 'triangle';
            osc.frequency.setValueAtTime(opts.freq, t);
            if (opts.detune) {
                osc.detune.setValueAtTime(opts.detune, t);
            }
            if (opts.glideTo) {
                osc.frequency.exponentialRampToValueAtTime(opts.glideTo, t + (opts.glideSec || 0.06));
            }
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(vol, t + 0.01);
            if (opts.sustain) {
                gain.gain.setValueAtTime(vol * opts.sustain, t + dur * 0.35);
            }
            gain.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            osc.connect(gain);
            gain.connect(dest);
            osc.start(t);
            osc.stop(t + dur + 0.04);
        } catch (e) {}
    }

    function playSparkle(start, vol) {
        [1567.98, 1975.53, 2349.32, 2793.83].forEach(function (freq, i) {
            playNote({
                freq: freq,
                start: start + i * 0.035,
                dur: 0.09,
                vol: vol * (0.55 - i * 0.08),
                type: 'sine'
            });
        });
    }

    function playFanfare(notes, sparkleVol) {
        notes.forEach(function (n) {
            playNote(n);
        });
        if (sparkleVol > 0) {
            var last = notes[notes.length - 1];
            playSparkle((last.start || 0) + (last.dur || 0.14) * 0.55, sparkleVol);
        }
    }

    function getTrashDeleteUrl() {
        var cfg = window.TNC_PR_PO_AUDIO || window.TNC_CRUD_AUDIO || {};
        return cfg.trashDeleteUrl || 'assets/audio/trash-delete.mp3';
    }

    function getTrashAudio() {
        if (!trashAudio) {
            trashAudio = new Audio(getTrashDeleteUrl());
            trashAudio.preload = 'auto';
            trashAudio.volume = 0.88;
        }
        return trashAudio;
    }

    function playTrashDeleteSound() {
        if (isMuted() || prefersReduced()) {
            return;
        }
        try {
            var audio = getTrashAudio();
            var url = getTrashDeleteUrl();
            if (!audio.src || audio.src.indexOf('trash-delete.mp3') === -1) {
                audio.src = url;
            }
            audio.currentTime = 0;
            var playPromise = audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    pendingKind = 'delete';
                });
            }
        } catch (e) {
            pendingKind = 'delete';
        }
    }

    var sounds = {
        create: function () {
            playNote({ freq: 65.41, start: 0, dur: 0.22, vol: 0.1, type: 'sine' });
            playFanfare([
                { freq: 523.25, start: 0, dur: 0.1, vol: 0.24, type: 'triangle' },
                { freq: 659.25, start: 0.065, dur: 0.1, vol: 0.24, type: 'triangle' },
                { freq: 783.99, start: 0.13, dur: 0.11, vol: 0.26, type: 'triangle' },
                { freq: 987.77, start: 0.195, dur: 0.12, vol: 0.27, type: 'triangle' },
                {
                    freq: 1046.5,
                    start: 0.26,
                    dur: 0.32,
                    vol: 0.3,
                    type: 'sine',
                    glideTo: 1318.51,
                    glideSec: 0.1,
                    sustain: 0.85
                }
            ], 0.14);
            playNote({
                freq: 1318.51,
                start: 0.38,
                dur: 0.35,
                vol: 0.12,
                type: 'triangle',
                detune: 8
            });
        },

        update: function () {
            playNote({ freq: 587.33, start: 0, dur: 0.1, vol: 0.16, type: 'triangle' });
            playNote({ freq: 739.99, start: 0.09, dur: 0.12, vol: 0.17, type: 'sine' });
        },

        approve: function () {
            playNote({ freq: 82.41, start: 0, dur: 0.18, vol: 0.08, type: 'sine' });
            playFanfare([
                { freq: 880, start: 0, dur: 0.09, vol: 0.24, type: 'triangle' },
                { freq: 1108.73, start: 0.06, dur: 0.09, vol: 0.25, type: 'triangle' },
                { freq: 1318.51, start: 0.12, dur: 0.11, vol: 0.27, type: 'triangle' },
                {
                    freq: 1760,
                    start: 0.19,
                    dur: 0.28,
                    vol: 0.29,
                    type: 'sine',
                    glideTo: 2093,
                    glideSec: 0.08,
                    sustain: 0.8
                }
            ], 0.12);
        },

        complete: function () {
            playNote({ freq: 880, start: 0, dur: 0.07, vol: 0.22, type: 'square' });
            playNote({ freq: 880, start: 0, dur: 0.07, vol: 0.1, type: 'sine', detune: -12 });
            playNote({ freq: 1318.51, start: 0.08, dur: 0.18, vol: 0.26, type: 'triangle' });
            playNote({ freq: 1760, start: 0.1, dur: 0.16, vol: 0.14, type: 'sine' });
            playSparkle(0.2, 0.1);
        },

        delete: function () {
            playTrashDeleteSound();
        }
    };

    function playSynth(kind) {
        var fn = sounds[kind] || sounds.update;
        var c = ensureCtx();
        if (!c) {
            pendingKind = kind;
            return;
        }
        if (c.state === 'suspended') {
            pendingKind = kind;
            return;
        }
        fn();
    }

    function playInternal(kind) {
        var normalized = normalizeKind(kind);
        if (!normalized || isMuted() || prefersReduced()) {
            return;
        }
        if (normalized === 'delete') {
            playTrashDeleteSound();
            return;
        }
        playSynth(normalized);
    }

    function play(kind) {
        playInternal(kind);
    }

    function flushPending() {
        if (!pendingKind || isMuted() || prefersReduced()) {
            return;
        }
        var k = pendingKind;
        pendingKind = null;
        if (k === 'delete') {
            playTrashDeleteSound();
            return;
        }
        var c = ensureCtx();
        if (!c || c.state === 'suspended') {
            pendingKind = k;
            return;
        }
        (sounds[k] || sounds.update)();
    }

    /**
     * สอดคล้องกับ tnc_audio_from_query() ใน includes/tnc_flash.php
     */
    function audioFromQuery(params) {
        if (params.get('error')) {
            return null;
        }
        if (params.get('deleted') || params.get('cancelled') || params.get('cat_deleted')) {
            return 'delete';
        }
        if (params.get('web_approved') || params.get('approved')) {
            return 'approve';
        }
        if (params.get('rejected') || params.get('web_rejected')) {
            return 'delete';
        }
        if (params.get('payment_saved') || params.get('billing_saved')) {
            return 'complete';
        }
        if (params.get('created') || params.get('success') || params.get('product_added')) {
            return 'create';
        }
        if (
            params.get('updated')
            || params.get('pr_updated')
            || params.get('payment_slips_updated')
            || params.get('invoice_updated')
            || params.get('saved')
            || params.get('cat_saved')
        ) {
            return 'update';
        }
        return null;
    }

    function detectFromDom() {
        var el = document.querySelector('[data-tnc-audio]');
        return el ? normalizeKind(el.getAttribute('data-tnc-audio')) : null;
    }

    function detectFromUrl() {
        if (typeof window.tncFlashSearchParams === 'function') {
            return audioFromQuery(window.tncFlashSearchParams());
        }
        return audioFromQuery(new URLSearchParams(window.location.search || ''));
    }

    function queryParamsFromDetail(detail) {
        detail = detail || {};
        if (detail.query && typeof detail.query === 'object') {
            try {
                return new URLSearchParams(detail.query);
            } catch (e) {}
        }
        if (detail.url) {
            try {
                var u = new URL(String(detail.url), window.location.origin);
                return u.searchParams;
            } catch (e2) {}
        }
        return null;
    }

    function inferAudioFromMessage(message, action) {
        var m = String(message || '');
        var ml = m.toLowerCase();
        var a = String(action || '').toLowerCase();
        if (a.indexOf('delete') !== -1 || ml.indexOf('ลบ') !== -1 || ml.indexOf('ยกเลิก') !== -1) {
            return 'delete';
        }
        if (a.indexOf('approv') !== -1 || ml.indexOf('อนุมัติ') !== -1) {
            return 'approve';
        }
        if (
            a.indexOf('payment') !== -1
            || a.indexOf('billing') !== -1
            || ml.indexOf('จ่าย') !== -1
            || ml.indexOf('บิล') !== -1
        ) {
            return 'complete';
        }
        if (
            a.indexOf('update') !== -1
            || a.indexOf('edit') !== -1
            || ml.indexOf('แก้ไข') !== -1
            || ml.indexOf('อัปเดต') !== -1
        ) {
            return 'update';
        }
        if (ml.indexOf('เพิ่ม') !== -1 || ml.indexOf('สร้าง') !== -1 || a.indexOf('creat') !== -1) {
            return 'create';
        }
        if (ml.indexOf('บันทึก') !== -1 || ml.indexOf('สำเร็จ') !== -1 || ml.indexOf('เรียบร้อย') !== -1) {
            return 'update';
        }
        return null;
    }

    function mapAjaxAction(action, detail) {
        detail = detail || {};
        var normalizedAction = String(action || '').trim();
        if (normalizedAction === 'site_saved') {
            return detail.mode === 'create' ? 'create' : 'update';
        }
        if (normalizedAction === 'po_created' || normalizedAction === 'save_pr') {
            return 'create';
        }
        if (
            normalizedAction === 'update_pr'
            || normalizedAction === 'update_po_direct'
            || normalizedAction === 'update_po_direct_hire'
        ) {
            return 'update';
        }
        if (normalizedAction === 'cancel_purchase_order' || normalizedAction === 'delete_pr') {
            return 'delete';
        }
        if (
            normalizedAction === 'update_po_payment_status'
            || normalizedAction === 'receive_po_bill'
            || normalizedAction === 'upload_po_payment_slip'
            || normalizedAction === 'add_po_payment_slips'
            || normalizedAction === 'replace_po_payment_slip'
        ) {
            return 'complete';
        }
        if (
            normalizedAction.slice(-8) === '_created'
            || (normalizedAction.slice(-6) === '_saved' && detail.mode === 'create')
        ) {
            return 'create';
        }
        if (normalizedAction.slice(-8) === '_deleted' || normalizedAction.slice(-11) === '_cancelled') {
            return 'delete';
        }
        if (normalizedAction.slice(-9) === '_approved') {
            return 'approve';
        }
        if (normalizedAction.slice(-8) === '_updated' || normalizedAction.slice(-6) === '_saved') {
            return 'update';
        }
        if (normalizedAction.indexOf('payment') !== -1 || normalizedAction.indexOf('billing') !== -1) {
            return 'complete';
        }
        if (normalizedAction.indexOf('po_payment') !== -1 || normalizedAction.indexOf('pr_') === 0) {
            return 'update';
        }
        return null;
    }

    function resolveAudioKind(detail) {
        detail = detail || {};
        var fromAudio = normalizeKind(detail.audio);
        if (fromAudio) {
            return fromAudio;
        }
        var fromAction = mapAjaxAction(detail.action, detail);
        if (fromAction) {
            return fromAction;
        }
        var params = queryParamsFromDetail(detail);
        if (params) {
            var fromQuery = audioFromQuery(params);
            if (fromQuery) {
                return fromQuery;
            }
        }
        return inferAudioFromMessage(detail.message, detail.action);
    }

    function playFromDetail(detail) {
        if (!detail || !detail.ok) {
            return;
        }
        var kind = resolveAudioKind(detail);
        if (kind) {
            lastAjaxAudioAt = Date.now();
            play(kind);
        }
    }

    function recentlyPlayedAjaxAudio() {
        return lastAjaxAudioAt > 0 && (Date.now() - lastAjaxAudioAt) < 2800;
    }

    function unlockAudio() {
        ensureCtx();
        flushPending();
        document.removeEventListener('click', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
        document.removeEventListener('touchstart', unlockAudio);
    }

    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);
    document.addEventListener('touchstart', unlockAudio);

    function onReady() {
        if (playedOnLoad || recentlyPlayedAjaxAudio()) {
            return;
        }
        var kind = detectFromDom() || detectFromUrl();
        if (!kind) {
            return;
        }
        playedOnLoad = true;
        setTimeout(function () {
            play(kind);
            flushPending();
        }, 120);
    }

    window.addEventListener('tnc:form-ajax-success', function (e) {
        playFromDetail(e.detail || {});
    });

    var api = {
        play: play,
        playFromDetail: playFromDetail,
        resolveAudioKind: resolveAudioKind,
        mute: function () {
            setMuted(true);
        },
        unmute: function () {
            setMuted(false);
        },
        isMuted: isMuted,
        toggle: function () {
            var nowMuted = !isMuted();
            setMuted(nowMuted);
            if (window.TncSoundSettings && typeof window.TncSoundSettings.syncNavbarToggles === 'function') {
                window.TncSoundSettings.syncNavbarToggles();
            }
            return nowMuted;
        }
    };

    window.TncCrudAudio = api;
    window.TncPrPoAudio = api;

    window.addEventListener('tnc:sound-settings-changed', function (e) {
        var d = e.detail || {};
        if (!d.muted && pendingKind) {
            flushPending();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
