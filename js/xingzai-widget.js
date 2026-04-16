(function () {
    if (document.getElementById('xingzaiFloat')) {
        return;
    }

    var script = document.currentScript;
    var body = document.body;
    if (!body) {
        return;
    }

    var apiUrl = (script && script.getAttribute('data-api')) || '/backend/xingzai_chat.php';
    var avatarUrl = (script && script.getAttribute('data-avatar')) || '/images/xingzai-avatar.jpg';

    var style = document.createElement('style');
    style.textContent = ''
        + '.xingzai-float{position:fixed;right:18px;bottom:98px;z-index:1200;touch-action:none;user-select:none;-webkit-user-select:none;}'
        + '.xingzai-trigger{width:68px;height:68px;border:0;border-radius:50%;background:#f5d9a0;box-shadow:0 18px 40px rgba(0,0,0,.22);padding:0;overflow:hidden;}'
        + '.xingzai-trigger img{width:100%;height:100%;object-fit:cover;display:block;}'
        + '.xingzai-panel{position:absolute;right:0;bottom:84px;width:min(360px,calc(100vw - 28px));background:rgba(16,21,28,.98);color:#fff;border-radius:24px;overflow:hidden;display:none;box-shadow:0 24px 60px rgba(0,0,0,.34);}'
        + '.xingzai-panel.open{display:block;}'
        + '.xingzai-head{padding:16px 18px;background:linear-gradient(135deg,#f2c46b,#f59f53);color:#16110a;font-weight:800;}'
        + '.xingzai-head small{display:block;margin-top:4px;font-weight:600;opacity:.8;}'
        + '.xingzai-messages{max-height:320px;overflow-y:auto;padding:14px;background:#111821;}'
        + '.xingzai-msg{position:relative;padding:12px 14px;border-radius:18px;margin-bottom:10px;line-height:1.75;white-space:pre-wrap;}'
        + '.xingzai-msg.bot{background:#1b2430;}'
        + '.xingzai-msg.user{background:#f2c46b;color:#17120b;}'
        + '.xingzai-lyric-box{position:relative;margin-top:12px;padding:14px 14px 42px;border-radius:16px;background:rgba(8,12,18,.55);border:1px solid rgba(255,255,255,.06);white-space:pre-wrap;line-height:1.8;}'
        + '.xingzai-copy-btn{position:absolute;right:10px;bottom:8px;width:34px;height:34px;border:0;border-radius:999px;background:rgba(255,255,255,.08);color:#ffd28a;font-size:16px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;}'
        + '.xingzai-copy-btn.is-done{color:#7df2b1;}'
        + '.xingzai-suggestions{display:flex;flex-wrap:wrap;gap:8px;padding:0 14px 12px;background:#111821;}'
        + '.xingzai-suggestions button{border:0;border-radius:999px;padding:8px 12px;background:#243142;color:#fff;font-size:12px;}'
        + '.xingzai-form{display:grid;grid-template-columns:1fr auto;gap:10px;padding:14px;background:#0d131a;}'
        + '.xingzai-form textarea{border:1px solid rgba(255,255,255,.08);border-radius:16px;background:#131c25;color:#fff;padding:12px 14px;min-height:74px;resize:none;width:100%;}'
        + '.xingzai-form button{border:0;border-radius:16px;min-width:74px;background:linear-gradient(135deg,#f2c46b,#f59f53);color:#17120b;font-weight:800;}'
        + '@media (max-width:768px){.xingzai-float{right:12px;bottom:88px;}.xingzai-trigger{width:60px;height:60px;}.xingzai-panel{width:min(340px,calc(100vw - 20px));bottom:74px;}}';
    document.head.appendChild(style);

    var wrapper = document.createElement('div');
    wrapper.className = 'xingzai-float';
    wrapper.id = 'xingzaiFloat';
    wrapper.innerHTML = ''
        + '<div class="xingzai-panel" id="xingzaiPanel">'
        + '<div class="xingzai-head">星仔机器人<small>可以回答站内音乐问题，也能直接帮你作词</small></div>'
        + '<div class="xingzai-messages" id="xingzaiMessages">'
        + '<div class="xingzai-msg bot">你好，我是星仔。你可以直接问我：STAR.AI 怎么用、混音和母带有什么区别，或者说“帮我写一段副歌歌词”。</div>'
        + '</div>'
        + '<div class="xingzai-suggestions" id="xingzaiSuggestions">'
        + '<button type="button">STAR.AI 怎么用</button>'
        + '<button type="button">混音和母带区别</button>'
        + '<button type="button">帮我写一段副歌歌词</button>'
        + '</div>'
        + '<form class="xingzai-form" id="xingzaiForm">'
        + '<textarea id="xingzaiInput" placeholder="例如：帮我写一段伤感流行歌副歌，或者网站怎么上传歌曲"></textarea>'
        + '<button type="submit">发送</button>'
        + '</form>'
        + '</div>'
        + '<button type="button" class="xingzai-trigger" id="xingzaiTrigger" aria-label="打开星仔机器人"><img src="' + avatarUrl + '" alt="星仔"></button>';
    body.appendChild(wrapper);

    var trigger = document.getElementById('xingzaiTrigger');
    var panel = document.getElementById('xingzaiPanel');
    var form = document.getElementById('xingzaiForm');
    var input = document.getElementById('xingzaiInput');
    var messages = document.getElementById('xingzaiMessages');
    var suggestions = document.getElementById('xingzaiSuggestions');

    function splitLyricMessage(text) {
        var raw = String(text || '').trim();
        var markerIndex = raw.search(/\[(主歌|副歌|verse|chorus)\]/i);
        if (markerIndex === -1) {
            return { intro: raw, lyrics: '', outro: '' };
        }
        var intro = raw.slice(0, markerIndex).trim();
        var lyricPart = raw.slice(markerIndex).trim();
        var outro = '';
        var tailIndex = lyricPart.indexOf('\n\n如果你愿意');
        if (tailIndex !== -1) {
            outro = lyricPart.slice(tailIndex).trim();
            lyricPart = lyricPart.slice(0, tailIndex).trim();
        }
        return { intro: intro, lyrics: lyricPart, outro: outro };
    }

    function fallbackCopyText(text) {
        var helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        helper.style.pointerEvents = 'none';
        helper.style.left = '-9999px';
        helper.style.top = '0';
        document.body.appendChild(helper);
        helper.focus();
        helper.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        document.body.removeChild(helper);
        return copied;
    }

    function bindCopyButton(button, copiedText) {
        button.addEventListener('click', function () {
            var markDone = function () {
                button.textContent = '✓';
                button.classList.add('is-done');
                window.setTimeout(function () {
                    button.textContent = '⧉';
                    button.classList.remove('is-done');
                }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(copiedText).then(markDone).catch(function () {
                    if (fallbackCopyText(copiedText)) {
                        markDone();
                    }
                });
            } else if (fallbackCopyText(copiedText)) {
                markDone();
            }
        });
    }

    function appendMessage(role, text) {
        var item = document.createElement('div');
        item.className = 'xingzai-msg ' + role;
        if (role === 'bot') {
            var parts = splitLyricMessage(text);
            if (parts.intro) {
                var intro = document.createElement('div');
                intro.textContent = parts.intro;
                item.appendChild(intro);
            }
            if (parts.lyrics) {
                var lyricBox = document.createElement('div');
                lyricBox.className = 'xingzai-lyric-box';
                lyricBox.appendChild(document.createTextNode(parts.lyrics));
                var copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'xingzai-copy-btn';
                copyBtn.textContent = '⧉';
                bindCopyButton(copyBtn, parts.lyrics);
                lyricBox.appendChild(copyBtn);
                item.appendChild(lyricBox);
            }
            if (parts.outro) {
                var outro = document.createElement('div');
                outro.style.marginTop = '12px';
                outro.textContent = parts.outro;
                item.appendChild(outro);
            }
            if (!parts.intro && !parts.lyrics && !parts.outro) {
                item.textContent = text;
            }
        } else {
            item.textContent = text;
        }
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    function renderSuggestions(items, loginUrl) {
        suggestions.innerHTML = '';
        (items || []).slice(0, 3).forEach(function (label) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.addEventListener('click', function () {
                if (label.indexOf('登录') !== -1 && loginUrl) {
                    window.location.href = loginUrl;
                    return;
                }
                input.value = label;
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            });
            suggestions.appendChild(button);
        });
    }

    var dragState = { active: false, moved: false, startX: 0, startY: 0, originLeft: 0, originTop: 0 };

    function beginDrag(clientX, clientY) {
        var rect = wrapper.getBoundingClientRect();
        wrapper.style.left = rect.left + 'px';
        wrapper.style.top = rect.top + 'px';
        wrapper.style.right = 'auto';
        wrapper.style.bottom = 'auto';
        dragState.active = true;
        dragState.moved = false;
        dragState.startX = clientX;
        dragState.startY = clientY;
        dragState.originLeft = rect.left;
        dragState.originTop = rect.top;
    }

    function moveDrag(clientX, clientY) {
        if (!dragState.active) {
            return;
        }
        var dx = clientX - dragState.startX;
        var dy = clientY - dragState.startY;
        if (Math.abs(dx) > 4 || Math.abs(dy) > 4) {
            dragState.moved = true;
        }
        var maxLeft = Math.max(0, window.innerWidth - wrapper.offsetWidth);
        var maxTop = Math.max(0, window.innerHeight - wrapper.offsetHeight);
        var nextLeft = Math.min(maxLeft, Math.max(0, dragState.originLeft + dx));
        var nextTop = Math.min(maxTop, Math.max(0, dragState.originTop + dy));
        wrapper.style.left = nextLeft + 'px';
        wrapper.style.top = nextTop + 'px';
    }

    function endDrag() {
        if (!dragState.active) {
            return false;
        }
        dragState.active = false;
        return dragState.moved;
    }

    trigger.addEventListener('click', function (event) {
        if (dragState.moved) {
            dragState.moved = false;
            event.preventDefault();
            return;
        }
        panel.classList.toggle('open');
    });

    trigger.addEventListener('mousedown', function (event) { beginDrag(event.clientX, event.clientY); });
    document.addEventListener('mousemove', function (event) { moveDrag(event.clientX, event.clientY); });
    document.addEventListener('mouseup', function () { endDrag(); });
    trigger.addEventListener('touchstart', function (event) {
        var touch = event.touches[0];
        beginDrag(touch.clientX, touch.clientY);
    }, { passive: true });
    document.addEventListener('touchmove', function (event) {
        if (!dragState.active) return;
        var touch = event.touches[0];
        moveDrag(touch.clientX, touch.clientY);
    }, { passive: true });
    document.addEventListener('touchend', function () { endDrag(); });

    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            panel.classList.remove('open');
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var value = input.value.trim();
        if (!value) {
            return;
        }
        appendMessage('user', value);
        input.value = '';
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: value })
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                appendMessage('bot', data.reply || '我刚刚有点走神了，你再问我一次。');
                renderSuggestions(data.suggestions || [], data.login_url || '');
                if (data.login_required) {
                    input.placeholder = '请先登录后再和星仔继续聊天';
                }
            })
            .catch(function () {
                appendMessage('bot', '我刚刚没有接上，请你再发一次。');
            });
    });
})();
