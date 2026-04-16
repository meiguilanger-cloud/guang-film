(function () {
    if (window.StarwavesGlobalPlayer) {
        window.StarwavesGlobalPlayer.refresh();
        return;
    }

    var STORAGE_KEY = 'starwaves.globalPlayer.state.v1';
    var STYLE_ID = 'starwaves-global-player-style';
    var ROOT_ID = 'starwavesGlobalPlayer';
    var AUDIO_ID = 'starwavesGlobalPlayerAudio';
    var TITLE_ID = 'starwavesGlobalPlayerTitle';
    var TIME_ID = 'starwavesGlobalPlayerTime';
    var TOGGLE_ID = 'starwavesGlobalPlayerToggle';
    var LYRICS_ID = 'starwavesGlobalPlayerLyrics';
    var SEEK_WRAP_ID = 'starwavesGlobalPlayerSeekWrap';
    var SEEK_ID = 'starwavesGlobalPlayerSeek';
    var WAVE_ID = 'starwavesGlobalPlayerWave';
    var BUBBLE_ID = 'starwavesGlobalPlayerBubble';
    var boundFlag = 'swGlobalPlayerBound';
    var rafId = null;
    var activeLyrics = [];
    var activeLyricIndex = -1;
    var waveSeed = 0;
    var isSeekingPreview = false;
    var seekResumeAfterCommit = false;
    var pendingSeekTime = null;

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = ''
            + '.sw-global-player{position:fixed;left:50%;bottom:12px;transform:translateX(-50%) translateY(calc(100% + 24px));width:min(33vw,460px);min-width:280px;z-index:99999;padding:10px 12px calc(10px + env(safe-area-inset-bottom, 0px));background:linear-gradient(140deg,rgba(10,14,20,.46),rgba(22,29,40,.28));border:1px solid rgba(255,255,255,.1);border-radius:24px;box-shadow:0 18px 42px rgba(0,0,0,.18);backdrop-filter:blur(26px);-webkit-backdrop-filter:blur(26px);overflow:hidden;opacity:0;visibility:hidden;pointer-events:none;transition:transform .28s ease,opacity .22s ease,visibility .22s ease;}'
            + '.sw-global-player.is-visible{transform:translateX(-50%) translateY(0);opacity:1;visibility:visible;pointer-events:auto;}'
            + '.sw-global-player__inner{max-width:1180px;margin:0 auto;display:flex;align-items:center;gap:12px;position:relative;padding-bottom:28px;}'
            + '.sw-global-player__toggle{width:50px;height:50px;border:1px solid rgba(255,255,255,.06);border-radius:16px;background:linear-gradient(180deg,rgba(30,34,40,.96),rgba(8,9,12,.98));box-shadow:inset 0 1px 0 rgba(255,255,255,.08),0 8px 18px rgba(0,0,0,.24);cursor:pointer;display:flex;align-items:center;justify-content:center;flex:0 0 50px;transition:transform .2s ease,box-shadow .2s ease;position:relative;}'
            + '.sw-global-player__toggle:hover{transform:translateY(-1px) scale(1.03);box-shadow:inset 0 1px 0 rgba(255,255,255,.1),0 10px 22px rgba(0,0,0,.28);}'
            + '.sw-global-player__toggle:before{content:"";display:block;width:0;height:0;border-top:10px solid transparent;border-bottom:10px solid transparent;border-left:15px solid rgba(255,255,255,.92);margin-left:4px;}'
            + '.sw-global-player.is-playing .sw-global-player__toggle:before{width:15px;height:18px;border:none;background:linear-gradient(90deg,rgba(255,255,255,.92) 0 5px,transparent 5px 10px,rgba(255,255,255,.92) 10px 15px);margin-left:0;}'
            + '.sw-global-player__meta{width:82px;min-width:0;}'
            + '.sw-global-player__title{color:#fff8eb;font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
            + '.sw-global-player__time{margin-top:4px;color:#e6d7b3;font-size:12px;}'
            + '.sw-global-player__center{flex:1;min-width:0;display:flex;flex-direction:column;gap:8px;padding:8px 12px;border-radius:16px;background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08);}'
            + '.sw-global-player__lyrics{min-width:0;height:58px;overflow:auto;scrollbar-width:none;-ms-overflow-style:none;}'
            + '.sw-global-player__lyrics::-webkit-scrollbar{display:none;}'
            + '.sw-global-player__lyrics-panel{display:flex;flex-direction:column;gap:8px;padding:12px 0;}'
            + '.sw-global-player__lyrics-line{padding:6px 10px;border-radius:12px;color:rgba(255,248,235,.42);font-size:13px;line-height:1.45;transition:all .2s ease;white-space:normal;overflow:visible;text-overflow:clip;text-align:center;word-break:break-word;overflow-wrap:anywhere;}'
            + '.sw-global-player__lyrics-line.is-active{background:rgba(255,244,214,.14);color:#fffaf0;font-weight:700;transform:scale(1.02);}'
            + '.sw-global-player__lyrics-empty{padding:10px 0;color:rgba(255,248,235,.52);font-size:12px;text-align:center;}'
            + '.sw-global-player__seek-wrap{position:absolute;left:0;right:0;bottom:0;height:22px;border-radius:999px;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02));overflow:visible;padding:0 10px;display:flex;align-items:center;touch-action:none;}'
            + '.sw-global-player__bubble{position:absolute;left:0;bottom:26px;transform:translateX(-50%) translateY(8px) scale(.96);padding:6px 10px;border-radius:999px;background:linear-gradient(180deg,rgba(18,22,28,.96),rgba(8,10,14,.98));border:1px solid rgba(255,255,255,.12);color:#fff8eb;font-size:11px;font-weight:700;line-height:1;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .18s ease,transform .18s ease;box-shadow:0 10px 22px rgba(0,0,0,.22);z-index:3;letter-spacing:.02em;}'
            + '.sw-global-player__bubble.is-visible{opacity:1;transform:translateX(-50%) translateY(0) scale(1);}'
            + '.sw-global-player__wave{position:absolute;left:10px;right:10px;top:50%;transform:translateY(-50%);display:flex;align-items:flex-end;gap:2px;height:18px;pointer-events:none;}'
            + '.sw-global-player__bar{flex:1 1 0;min-width:3px;border-radius:999px;background:rgba(255,255,255,.18);transition:background .18s ease,opacity .18s ease,transform .18s ease;opacity:.92;}'
            + '.sw-global-player__bar.is-played{background:linear-gradient(180deg,rgba(255,244,214,.98),rgba(214,190,132,.92));box-shadow:0 0 10px rgba(255,239,198,.18);}'
            + '.sw-global-player.is-playing .sw-global-player__bar.is-current{transform:scaleY(1.08);background:#fff7e2;}'
            + '.sw-global-player__seek{position:absolute;inset:0;opacity:0;appearance:none;-webkit-appearance:none;background:transparent;width:100%;margin:0;cursor:pointer;z-index:2;pointer-events:auto;touch-action:none;}'
            + '.sw-global-player__audio{display:none !important;}'
            + 'body.sw-global-player-active{padding-bottom:136px !important;}'
            + '@media (max-width:768px){.sw-global-player{left:50%;bottom:8px;transform:translateX(-50%) translateY(calc(100% + 24px));width:calc(100vw - 16px);min-width:0;padding:10px 12px calc(10px + env(safe-area-inset-bottom, 0px));border-radius:22px;}.sw-global-player.is-visible{transform:translateX(-50%) translateY(0);}.sw-global-player__inner{gap:8px;padding-bottom:26px;align-items:flex-start;}.sw-global-player__toggle{width:46px;height:46px;flex-basis:46px;}.sw-global-player__meta{width:72px;}.sw-global-player__title{font-size:13px;}.sw-global-player__time{font-size:11px;}.sw-global-player__center{flex:1;min-width:0;padding:8px 10px;}.sw-global-player__lyrics{height:64px;}.sw-global-player__lyrics-panel{gap:8px;padding:8px 0;}.sw-global-player__lyrics-line{font-size:12px;line-height:1.4;padding:6px 8px;}.sw-global-player__seek-wrap{height:22px;padding:0 6px;}.sw-global-player__wave{left:6px;right:6px;gap:1px;}.sw-global-player__bar{min-width:2px;}body.sw-global-player-active{padding-bottom:140px !important;}}';
        document.head.appendChild(style);
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function hashText(text) {
        var hash = 0;
        var str = String(text || '');
        for (var i = 0; i < str.length; i++) {
            hash = ((hash << 5) - hash) + str.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash);
    }

    function buildWaveMarkup(seed) {
        var count = 34;
        var bars = [];
        var base = seed || 1;
        for (var i = 0; i < count; i++) {
            var h = 22 + (((base + i * 17) % 55));
            if (i % 5 === 0) h += 14;
            if (i % 7 === 0) h -= 8;
            h = Math.max(18, Math.min(92, h));
            bars.push('<span class="sw-global-player__bar" data-wave-index="' + i + '" style="height:' + h + '%"></span>');
        }
        return bars.join('');
    }

    function ensurePlayer() {
        injectStyle();
        var root = document.getElementById(ROOT_ID) || document.getElementById('globalBottomPlayer') || document.getElementById('songBottomPlayer');
        var existingAudio = root ? (root.querySelector('#' + AUDIO_ID) || root.querySelector('#globalBottomAudio') || root.querySelector('#songBottomAudio') || root.querySelector('audio')) : null;
        var existingTitle = root ? (root.querySelector('#' + TITLE_ID) || root.querySelector('#globalBottomTitle') || root.querySelector('#songBottomTitle')) : null;
        var existingTime = root ? (root.querySelector('#' + TIME_ID) || root.querySelector('#globalBottomTime') || root.querySelector('#songBottomTime')) : null;

        if (!root) {
            root = document.createElement('div');
            document.body.appendChild(root);
        }
        if (!existingAudio) {
            existingAudio = document.createElement('audio');
            root.appendChild(existingAudio);
        }

        if (!root.querySelector('#' + TOGGLE_ID) || !root.querySelector('#' + LYRICS_ID) || !root.querySelector('#' + SEEK_ID)) {
            root.innerHTML = ''
                + '<div class="sw-global-player__inner">'
                + '<button id="' + TOGGLE_ID + '" type="button" class="sw-global-player__toggle" aria-label="播放或暂停"></button>'
                + '<div class="sw-global-player__meta">'
                + '<div id="' + TITLE_ID + '" class="sw-global-player__title">' + escapeHtml(existingTitle ? existingTitle.textContent : '请选择歌曲') + '</div>'
                + '<div id="' + TIME_ID + '" class="sw-global-player__time">' + escapeHtml(existingTime ? existingTime.textContent : '00:00 / 00:00') + '</div>'
                + '</div>'
                + '<div class="sw-global-player__center">'
                + '<div id="' + LYRICS_ID + '" class="sw-global-player__lyrics"><div class="sw-global-player__lyrics-panel"></div></div>'
                + '</div>'
                + '<div id="' + SEEK_WRAP_ID + '" class="sw-global-player__seek-wrap">'
                + '<div id="' + BUBBLE_ID + '" class="sw-global-player__bubble">00:00</div>'
                + '<div id="' + WAVE_ID + '" class="sw-global-player__wave"></div>'
                + '<input id="' + SEEK_ID + '" class="sw-global-player__seek" type="range" min="0" max="1000" step="1" value="0" aria-label="播放进度">'
                + '</div>'
                + '</div>';
            root.appendChild(existingAudio);
        }

        root.classList.add('sw-global-player');
        root.id = ROOT_ID;
        var title = root.querySelector('#' + TITLE_ID);
        var time = root.querySelector('#' + TIME_ID);
        var toggle = root.querySelector('#' + TOGGLE_ID);
        var lyrics = root.querySelector('#' + LYRICS_ID);
        var seek = root.querySelector('#' + SEEK_ID);
        var wave = root.querySelector('#' + WAVE_ID);
        var bubble = root.querySelector('#' + BUBBLE_ID);
        var audio = root.querySelector('#' + AUDIO_ID) || root.querySelector('#globalBottomAudio') || root.querySelector('#songBottomAudio') || root.querySelector('audio');
        if (audio) {
            audio.id = AUDIO_ID;
            audio.setAttribute('preload', 'none');
            audio.className = 'sw-global-player__audio';
            audio.removeAttribute('controls');
            audio.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
            audio.removeAttribute('data-title');
        }
        if (wave && !wave.children.length) {
            wave.innerHTML = buildWaveMarkup(waveSeed);
        }
        var seekWrap = root.querySelector('#' + SEEK_WRAP_ID);
        return { root: root, audio: audio, title: title, time: time, toggle: toggle, lyrics: lyrics, seek: seek, seekWrap: seekWrap, wave: wave, bubble: bubble };
    }

    function fmt(sec) {
        if (!isFinite(sec) || sec < 0) return '00:00';
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function readState() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (error) {
            return null;
        }
    }

    function writeState(extra) {
        var player = ensurePlayer();
        if (!player.audio) return;
        var state = {
            src: player.audio.currentSrc || player.audio.src || '',
            title: player.title ? player.title.textContent : '当前歌曲',
            currentTime: player.audio.currentTime || 0,
            paused: player.audio.paused,
            updatedAt: Date.now()
        };
        if (extra) {
            for (var key in extra) state[key] = extra[key];
        }
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (error) {}
    }

    function paintWaveAtRatio(ratio) {
        var player = ensurePlayer();
        if (!player.wave) return;
        var bars = player.wave.querySelectorAll('.sw-global-player__bar');
        if (!bars.length) return;
        ratio = Math.max(0, Math.min(1, ratio || 0));
        var playedCount = Math.round(ratio * bars.length);
        Array.prototype.forEach.call(bars, function (bar, index) {
            bar.classList.toggle('is-played', index < playedCount);
            bar.classList.toggle('is-current', index === Math.min(bars.length - 1, playedCount));
        });
        if (player.seek) {
            player.seek.value = String(Math.round(ratio * 1000));
        }
    }

    function updateWave() {
        var player = ensurePlayer();
        if (!player.audio || !player.wave || isSeekingPreview) return;
        var duration = player.audio.duration || 0;
        var percent = duration > 0 ? Math.max(0, Math.min(1, player.audio.currentTime / duration)) : 0;
        paintWaveAtRatio(percent);
    }

    function updateTime() {
        var player = ensurePlayer();
        if (!player.audio || !player.time) return;
        var staticDurationLabel = player.audio.getAttribute('data-duration-label') || '--:--';
        var totalLabel = (isFinite(player.audio.duration) && player.audio.duration > 0)
            ? fmt(player.audio.duration)
            : staticDurationLabel;
        if (!isSeekingPreview) {
            player.time.textContent = fmt(player.audio.currentTime) + ' / ' + totalLabel;
        }
        updateWave();
    }

    function readLyricsFromSource(source) {
        if (source && source.dataset && source.dataset.lyricsJson) {
            try {
                return JSON.parse(source.dataset.lyricsJson) || [];
            } catch (error) {
                return [];
            }
        }
        if (window.StarwavesCurrentLyrics && Array.isArray(window.StarwavesCurrentLyrics)) {
            return window.StarwavesCurrentLyrics;
        }
        return [];
    }

    function renderLyrics(items) {
        var player = ensurePlayer();
        if (!player.lyrics) return;
        var panel = player.lyrics.querySelector('.sw-global-player__lyrics-panel');
        if (!panel) return;
        activeLyrics = Array.isArray(items) ? items.filter(function (item) {
            return item && typeof item.time === 'number' && item.text;
        }) : [];
        activeLyricIndex = -1;
        if (!activeLyrics.length) {
            panel.innerHTML = '<div class="sw-global-player__lyrics-empty">这首歌暂时还没有可滚动歌词。</div>';
            return;
        }
        panel.innerHTML = activeLyrics.map(function (item, index) {
            return '<div class="sw-global-player__lyrics-line" data-lyric-index="' + index + '">' + escapeHtml(item.text) + '</div>';
        }).join('');
    }

    function syncLyrics(currentTime) {
        if (!activeLyrics.length) return;
        var player = ensurePlayer();
        var panel = player.lyrics ? player.lyrics.querySelector('.sw-global-player__lyrics-panel') : null;
        if (!panel) return;
        var index = -1;
        for (var i = 0; i < activeLyrics.length; i++) {
            if (currentTime >= activeLyrics[i].time) {
                index = i;
            } else {
                break;
            }
        }
        if (index === activeLyricIndex || index < 0) return;
        activeLyricIndex = index;
        var lines = panel.querySelectorAll('.sw-global-player__lyrics-line');
        Array.prototype.forEach.call(lines, function (line, lineIndex) {
            line.classList.toggle('is-active', lineIndex === index);
        });
        var activeLine = panel.querySelector('[data-lyric-index="' + index + '"]');
        if (activeLine) {
            activeLine.scrollIntoView({ block: 'center', behavior: 'smooth', inline: 'nearest' });
        }
    }

    function syncLoop() {
        updateTime();
        writeState();
        rafId = window.requestAnimationFrame(syncLoop);
    }

    function startSync() {
        if (rafId) return;
        syncLoop();
    }

    function stopSync() {
        if (!rafId) return;
        window.cancelAnimationFrame(rafId);
        rafId = null;
    }

    function updateSeekBubbleFromRatio(ratio) {
        var player = ensurePlayer();
        if (!player.bubble || !player.seek) return;
        ratio = Math.max(0, Math.min(1, ratio));
        var duration = player.audio && isFinite(player.audio.duration) ? player.audio.duration : 0;
        player.bubble.textContent = fmt(duration * ratio);
        player.bubble.style.left = (ratio * player.seek.clientWidth) + 'px';
        player.bubble.classList.add('is-visible');
    }

    function ratioFromClientX(clientX) {
        var player = ensurePlayer();
        var target = player.seekWrap || player.seek;
        if (!target || !isFinite(clientX)) return 0;
        var rect = target.getBoundingClientRect();
        if (rect.width <= 0) return 0;
        return Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    }

    function getPointClientX(event) {
        if (!event) return null;
        if (typeof event.clientX === 'number' && isFinite(event.clientX)) return event.clientX;
        var touch = event.touches && event.touches[0] ? event.touches[0] : (event.changedTouches && event.changedTouches[0] ? event.changedTouches[0] : null);
        return touch && typeof touch.clientX === 'number' ? touch.clientX : null;
    }

    function hideSeekBubble() {
        var player = ensurePlayer();
        if (!player.bubble) return;
        player.bubble.classList.remove('is-visible');
    }

    function previewSeekRatio(ratio) {
        var player = ensurePlayer();
        ratio = Math.max(0, Math.min(1, ratio));
        isSeekingPreview = true;
        updateSeekBubbleFromRatio(ratio);
        paintWaveAtRatio(ratio);
        if (player.time && player.audio) {
            var duration = isFinite(player.audio.duration) ? player.audio.duration : 0;
            player.time.textContent = fmt(duration * ratio) + ' / ' + fmt(duration);
        }
    }

    function finalizePendingSeek() {
        var player = ensurePlayer();
        if (!player.audio || pendingSeekTime === null) return;
        var targetTime = pendingSeekTime;
        var duration = player.audio.duration || 0;
        if (isFinite(duration) && duration > 0) {
            try {
                player.audio.currentTime = targetTime;
            } catch (error) {}
            if (player.seek) {
                player.seek.value = String(Math.round((targetTime / duration) * 1000));
            }
        }
        isSeekingPreview = false;
        pendingSeekTime = null;
        updateTime();
        syncLyrics(targetTime);
        writeState({ currentTime: targetTime, paused: !seekResumeAfterCommit });
        if (seekResumeAfterCommit) {
            var playPromise = player.audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {});
            }
        }
        seekResumeAfterCommit = false;
    }

    function applySeekRatio(ratio) {
        var player = ensurePlayer();
        if (!player.audio) return;
        ratio = Math.max(0, Math.min(1, ratio));
        var duration = player.audio.duration;
        if (isFinite(duration) && duration > 0) {
            pendingSeekTime = duration * ratio;
            finalizePendingSeek();
        } else {
            isSeekingPreview = false;
            pendingSeekTime = null;
            seekResumeAfterCommit = false;
            updateTime();
        }
    }

    function dispatchPlayerState(playing, src) {
        document.dispatchEvent(new CustomEvent('starwaves:player-state', {
            detail: {
                playing: !!playing,
                src: src || ''
            }
        }));
    }

    function playTrack(config) {
        var player = ensurePlayer();
        if (!player.audio || !config || !config.src) return;
        var src = config.src;
        var title = config.title || document.title || '当前歌曲';
        var durationLabel = config.durationLabel || '--:--';
        if (player.title) {
            player.title.textContent = title;
        }
        if (player.time) {
            player.time.textContent = '00:00 / ' + durationLabel;
        }
        player.audio.setAttribute('data-duration-label', durationLabel);
        waveSeed = hashText(title || src);
        if (player.wave) {
            player.wave.innerHTML = buildWaveMarkup(waveSeed);
        }
        if (player.audio.currentSrc !== src && player.audio.src !== src) {
            player.audio.src = src;
        }
        renderLyrics(Array.isArray(config.lyrics) ? config.lyrics : []);
        try {
            player.audio.currentTime = typeof config.currentTime === 'number' ? config.currentTime : 0;
        } catch (error) {}
        player.root.classList.add('is-visible', 'is-playing');
        document.body.classList.add('sw-global-player-active');
        dispatchPlayerState(true, src);
        var playPromise = player.audio.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function () {});
        }
        writeState({
            src: src,
            title: title,
            paused: false,
            currentTime: typeof config.currentTime === 'number' ? config.currentTime : 0,
            durationLabel: durationLabel
        });
    }

    function handoffFrom(source) {
        var src = source.currentSrc || source.src;
        if (!src) return;
        playTrack({
            src: src,
            title: source.getAttribute('data-title') || source.dataset.title || document.title || '当前歌曲',
            durationLabel: source.getAttribute('data-duration-label') || source.dataset.durationLabel || '--:--',
            lyrics: readLyricsFromSource(source),
            currentTime: source.currentTime || 0
        });
        source.pause();
    }

    function bindSourceAudio(scope) {
        var list = (scope || document).querySelectorAll('audio');
        Array.prototype.forEach.call(list, function (audio) {
            if (!audio || audio.id === AUDIO_ID || audio.dataset[boundFlag] === '1') {
                return;
            }
            audio.dataset[boundFlag] = '1';
            if (!audio.classList.contains('song-player')) {
                audio.classList.add('song-player');
            }
            audio.addEventListener('play', function () {
                handoffFrom(audio);
            });
        });
    }

    function restore() {
        var player = ensurePlayer();
        var state = readState();
        if (!player.audio || !state || !state.src) return;
        player.audio.src = state.src;
        if (player.title) {
            player.title.textContent = state.title || '当前歌曲';
        }
        if (player.time && state.durationLabel) {
            player.time.textContent = '00:00 / ' + state.durationLabel;
        }
        waveSeed = hashText(state.title || state.src);
        if (player.wave) {
            player.wave.innerHTML = buildWaveMarkup(waveSeed);
        }
        renderLyrics(window.StarwavesCurrentLyrics || []);
        if (!state.paused) {
            player.root.classList.add('is-visible', 'is-playing');
            document.body.classList.add('sw-global-player-active');
        } else {
            player.root.classList.remove('is-visible', 'is-playing');
            document.body.classList.remove('sw-global-player-active');
        }
        var seekAfterMeta = function () {
            try {
                if (typeof state.currentTime === 'number' && state.currentTime > 0) {
                    player.audio.currentTime = state.currentTime;
                }
            } catch (error) {}
            updateTime();
            syncLyrics(player.audio.currentTime || 0);
            if (!state.paused) {
                var playPromise = player.audio.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(function () {});
                }
            }
        };
        if (player.audio.readyState >= 1) {
            seekAfterMeta();
        } else {
            player.audio.addEventListener('loadedmetadata', seekAfterMeta, { once: true });
        }
    }

    function bindSeekInteractions(player) {
        if (!player.seekWrap || player.seekWrap.dataset.boundSeek === '1') return;
        player.seekWrap.dataset.boundSeek = '1';
        var dragging = false;
        var activePointerId = null;

        function syncSeekUi(ratio) {
            ratio = Math.max(0, Math.min(1, ratio));
            if (player.seek) {
                player.seek.value = String(Math.round(ratio * 1000));
            }
            updateSeekBubbleFromRatio(ratio);
            paintWaveAtRatio(ratio);
        }

        function commitRatio(ratio) {
            ratio = Math.max(0, Math.min(1, ratio));
            syncSeekUi(ratio);
            isSeekingPreview = false;
            applySeekRatio(ratio);
        }

        function previewRatio(ratio) {
            ratio = Math.max(0, Math.min(1, ratio));
            isSeekingPreview = true;
            syncSeekUi(ratio);
            if (player.time && player.audio) {
                var duration = isFinite(player.audio.duration) ? player.audio.duration : 0;
                player.time.textContent = fmt(duration * ratio) + ' / ' + fmt(duration);
            }
            applySeekRatio(ratio);
        }

        function beginDrag(event) {
            dragging = true;
            activePointerId = typeof event.pointerId === 'number' ? event.pointerId : null;
            seekResumeAfterCommit = !!(player.audio && !player.audio.paused);
            var clientX = getPointClientX(event);
            if (clientX !== null) {
                previewRatio(ratioFromClientX(clientX));
            }
        }

        function moveDrag(event) {
            if (!dragging) return;
            if (activePointerId !== null && typeof event.pointerId === 'number' && event.pointerId !== activePointerId) return;
            var clientX = getPointClientX(event);
            if (clientX === null) return;
            previewRatio(ratioFromClientX(clientX));
        }

        function endDrag(event, commit) {
            if (!dragging) return;
            if (event && activePointerId !== null && typeof event.pointerId === 'number' && event.pointerId !== activePointerId) return;
            var clientX = event ? getPointClientX(event) : null;
            if (commit && clientX !== null) {
                commitRatio(ratioFromClientX(clientX));
            } else if (!commit) {
                isSeekingPreview = false;
                pendingSeekTime = null;
                seekResumeAfterCommit = false;
                updateTime();
            }
            dragging = false;
            activePointerId = null;
            hideSeekBubble();
        }

        player.seekWrap.addEventListener('pointerdown', function (event) {
            beginDrag(event);
            if (player.seekWrap.setPointerCapture && typeof event.pointerId === 'number') {
                try { player.seekWrap.setPointerCapture(event.pointerId); } catch (error) {}
            }
            event.preventDefault();
        }, { passive: false });
        window.addEventListener('pointermove', function (event) {
            moveDrag(event);
            if (dragging) event.preventDefault();
        }, { passive: false });
        window.addEventListener('pointerup', function (event) {
            if (!dragging) return;
            endDrag(event, true);
            event.preventDefault();
        }, { passive: false });
        window.addEventListener('pointercancel', function (event) {
            if (!dragging) return;
            endDrag(event, false);
            event.preventDefault();
        }, { passive: false });
        player.seekWrap.addEventListener('touchstart', function (event) {
            beginDrag(event);
            event.preventDefault();
        }, { passive: false });
        window.addEventListener('touchmove', function (event) {
            if (!dragging) return;
            moveDrag(event);
            event.preventDefault();
        }, { passive: false });
        window.addEventListener('touchend', function (event) {
            if (!dragging) return;
            endDrag(event, true);
            event.preventDefault();
        }, { passive: false });
        window.addEventListener('touchcancel', function (event) {
            if (!dragging) return;
            endDrag(event, false);
            event.preventDefault();
        }, { passive: false });
    }

    function init() {
        var player = ensurePlayer();
        if (!player.audio) return;
        bindSourceAudio(document);
        bindSeekInteractions(player);
        renderLyrics(window.StarwavesCurrentLyrics || []);
        restore();
        player.audio.addEventListener('play', function () {
            player.root.classList.add('is-playing');
            dispatchPlayerState(true, player.audio.currentSrc || player.audio.src || '');
            startSync();
            writeState({ paused: false });
        });
        player.audio.addEventListener('timeupdate', function () {
            if (pendingSeekTime !== null) {
                finalizePendingSeek();
                return;
            }
            syncLyrics(player.audio.currentTime);
            updateTime();
        });
        player.audio.addEventListener('pause', function () {
            player.root.classList.remove('is-playing');
            dispatchPlayerState(false, player.audio.currentSrc || player.audio.src || '');
            stopSync();
            writeState({ paused: true });
        });
        player.audio.addEventListener('ended', function () {
            player.root.classList.remove('is-playing');
            dispatchPlayerState(false, player.audio.currentSrc || player.audio.src || '');
            stopSync();
            writeState({ paused: true, currentTime: 0 });
            updateTime();
        });
        player.audio.addEventListener('loadedmetadata', function () {
            if (pendingSeekTime !== null) {
                finalizePendingSeek();
                return;
            }
            updateTime();
            syncLyrics(player.audio.currentTime || 0);
        });
        player.audio.addEventListener('seeked', function () {
            if (pendingSeekTime !== null) {
                finalizePendingSeek();
            }
        });
        if (player.toggle) {
            player.toggle.addEventListener('click', function () {
                if (!player.audio || !player.audio.src) return;
                if (player.audio.paused) {
                    var playPromise = player.audio.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(function () {});
                    }
                } else {
                    player.audio.pause();
                }
            });
        }
        window.addEventListener('beforeunload', function () {
            writeState();
        });
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (node && node.nodeType === 1) {
                        bindSourceAudio(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function setPlayerVisible(visible) {
        var player = ensurePlayer();
        if (!player.root) return;
        player.root.classList.toggle('is-visible', !!visible);
        document.body.classList.toggle('sw-global-player-active', !!visible);
    }

    window.StarwavesGlobalPlayer = {
        refresh: function () {
            bindSourceAudio(document);
            var player = ensurePlayer();
            bindSeekInteractions(player);
            updateTime();
        },
        playTrack: function (config) {
            playTrack(config || {});
        },
        show: function () {
            setPlayerVisible(true);
        },
        hide: function () {
            setPlayerVisible(false);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
