/**
 * Audio feedback for PR / PO flows (create, update, approve, payment, billing).
 * Web Audio API for most sounds; delete uses assets/audio/trash-delete.mp3.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'tnc_pr_po_audio_muted';
    var ctx = null;
    var masterGain = null;
    var trashAudio = null;
    var pendingKind = null;
    var playedOnLoad = false;

    function isMuted() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function setMuted(muted) {
        try {
            if (muted) {
                localStorage.setItem(STORAGE_KEY, '1');
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {}
    }

    function prefersReduced() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function isPurchasePage() {
        return /\/pages\/purchase\//.test(window.location.pathname || '');
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
        var cfg = window.TNC_PR_PO_AUDIO || {};
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
        /** สร้าง PR/PO — fanfare ขึ้นพร้อม shimmer */
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

        /** อนุมัติ PR — โทนชนะ สว่างขึ้น */
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

        /** จ่ายเงิน / บิล — cha-ching */
        complete: function () {
            playNote({ freq: 880, start: 0, dur: 0.07, vol: 0.22, type: 'square' });
            playNote({ freq: 880, start: 0, dur: 0.07, vol: 0.1, type: 'sine', detune: -12 });
            playNote({ freq: 1318.51, start: 0.08, dur: 0.18, vol: 0.26, type: 'triangle' });
            playNote({ freq: 1760, start: 0.1, dur: 0.16, vol: 0.14, type: 'sine' });
            playSparkle(0.2, 0.1);
        },

        /** ลบ / ยกเลิก — ไฟล์เสียงถังขยะ */
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
        if (isMuted() || prefersReduced()) {
            return;
        }
        if (kind === 'delete') {
            playTrashDeleteSound();
            return;
        }
        playSynth(kind);
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

    function detectFromDom() {
        if (!isPurchasePage()) {
            return null;
        }
        var el = document.querySelector('[data-tnc-audio]');
        return el ? String(el.getAttribute('data-tnc-audio') || '').trim() : null;
    }

    function detectFromUrl() {
        if (!isPurchasePage()) {
            return null;
        }
        var p = new URLSearchParams(window.location.search);
        if (p.get('created') === '1') {
            return 'create';
        }
        if (p.get('success') === '1') {
            return 'create';
        }
        if (p.get('web_approved') === '1') {
            return 'approve';
        }
        if (p.get('updated') === '1' || p.get('pr_updated') === '1' || p.get('payment_slips_updated') === '1') {
            return 'update';
        }
        if (p.get('payment_saved') === '1' || p.get('billing_saved') === '1') {
            return 'complete';
        }
        if (p.get('deleted') === '1' || p.get('cancelled') === '1') {
            return 'delete';
        }
        return null;
    }

    function mapAjaxAction(action) {
        if (!action) {
            return null;
        }
        if (action === 'po_created' || action === 'save_pr') {
            return 'create';
        }
        if (action === 'update_pr' || action === 'update_po_direct' || action === 'update_po_direct_hire') {
            return 'update';
        }
        if (action === 'cancel_purchase_order' || action === 'delete_pr') {
            return 'delete';
        }
        if (
            action === 'update_po_payment_status'
            || action === 'receive_po_bill'
            || action === 'upload_po_payment_slip'
            || action === 'add_po_payment_slips'
            || action === 'replace_po_payment_slip'
        ) {
            return 'complete';
        }
        if (action.indexOf('po_payment') !== -1 || action.indexOf('pr_') === 0) {
            return 'update';
        }
        return null;
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

    function injectMuteToggle() {
        if (!isPurchasePage() || document.getElementById('tncPrPoAudioToggle')) {
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'tncPrPoAudioToggle';
        btn.className = 'tnc-pr-po-audio-toggle btn btn-light btn-sm shadow-sm border';
        btn.title = 'เปิด/ปิดเสียงเมื่อบันทึก PR/PO';
        btn.setAttribute('aria-label', 'เปิด/ปิดเสียงเมื่อบันทึก PR/PO');

        function syncIcon() {
            var muted = isMuted();
            btn.innerHTML = muted
                ? '<i class="bi bi-volume-mute-fill text-muted"></i>'
                : '<i class="bi bi-volume-up-fill text-primary"></i>';
            btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        }

        btn.addEventListener('click', function () {
            setMuted(!isMuted());
            syncIcon();
            if (!isMuted()) {
                play('create');
            }
        });

        document.body.appendChild(btn);
        syncIcon();
    }

    function onReady() {
        injectMuteToggle();
        if (playedOnLoad) {
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
        var d = e.detail || {};
        if (!d.ok) {
            return;
        }
        var kind = mapAjaxAction(d.action);
        if (kind) {
            play(kind);
        }
    });

    window.TncPrPoAudio = {
        play: play,
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
            return nowMuted;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
