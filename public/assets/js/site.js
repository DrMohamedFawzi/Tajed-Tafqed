/* ==========================================================================
   فَقَدتُ ووَجَدتُ - Shared page script
   Icons, toasts, WhatsApp sharing, revealing a phone number, ownership check.
   Buttons are wired with data attributes:
     data-action="reveal" data-id="12"
     data-action="verify" data-id="12" data-question="..."
     data-action="share"  data-text="..."
     data-action="copy"   data-text="..."
   ========================================================================== */

// Icon markup from the inline sprite (core/icons.php)
function ic(name) {
    return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + name + '"/></svg>';
}

function escHtml(s) {
    if (s === null || s === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

function escAttr(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Swap only a button's text label so its icon is kept
function setLabel(btn, text) {
    var lbl = btn.querySelector('.lbl');
    if (lbl) { lbl.textContent = text; } else { btn.textContent = text; }
}

// Small on-page message instead of the browser's alert()
var toastTimer = null;
function showToast(message, type) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.className = 'toast show' + (type ? ' toast-' + type : '');
    t.textContent = message;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { t.className = 'toast'; }, 4500);
}

function postJSON(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    }).then(function(r) { return r.json(); });
}

// Open WhatsApp with the text ready (adds the site link)
function shareWhatsApp(text) {
    var full = text + '\n' + location.origin + '/';
    window.open('https://wa.me/?text=' + encodeURIComponent(full), '_blank', 'noopener');
}

function copyText(btn, text) {
    var lbl = btn.querySelector('.lbl');
    var old = lbl ? lbl.textContent : btn.textContent;
    var done = function() {
        setLabel(btn, 'تم النسخ');
        setTimeout(function() { setLabel(btn, old); }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function() { showToast('تعذر النسخ، انسخ الرمز يدوياً.', 'error'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { showToast('تعذر النسخ، انسخ الرمز يدوياً.', 'error'); }
        document.body.removeChild(ta);
    }
}

// Contact block shown after a number is revealed
function contactHtml(data) {
    var html = '<div class="contact-reveal">';
    html += '<a href="tel:' + escAttr(data.contact_info) + '" class="btn btn-primary btn-sm">' + ic('phone') + ' اتصل: <span dir="ltr">' + escHtml(data.contact_info) + '</span></a>';
    if (data.contact_alt) {
        html += '<a href="tel:' + escAttr(data.contact_alt) + '" class="btn btn-outline-primary btn-sm">' + ic('phone') + ' رقم بديل: <span dir="ltr">' + escHtml(data.contact_alt) + '</span></a>';
    }
    html += '</div>';
    if (data.drop_point) {
        html += '<div class="drop-point-pill" style="margin-top:0.5rem;">' + ic('package') + '<span>مكان الاستلام:</span><strong>' + escHtml(data.drop_point) + '</strong></div>';
    }
    return html;
}

function revealContact(btn) {
    var id = btn.getAttribute('data-id');
    btn.disabled = true;
    setLabel(btn, 'جارٍ العرض...');
    postJSON('api/contact.php', {item_id: id})
        .then(function(data) {
            if (data.success) {
                var wrap = document.createElement('div');
                wrap.innerHTML = contactHtml(data);
                btn.replaceWith(wrap);
            } else {
                btn.disabled = false;
                setLabel(btn, 'إظهار رقم الجوال');
                showToast(data.message || 'تعذر عرض الرقم.', 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            setLabel(btn, 'إظهار رقم الجوال');
            showToast('تعذر الاتصال بالإنترنت، حاول مرة أخرى.', 'error');
        });
}

/* ---------- Ownership check (secret question) dialog ---------- */
var verifyLastFocus = null;

function ensureVerifyModal() {
    var m = document.getElementById('verifyModal');
    if (m) return m;
    m = document.createElement('div');
    m.id = 'verifyModal';
    m.className = 'modal-backdrop';
    m.innerHTML =
        '<div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="verifyModalTitle">' +
            '<button type="button" class="modal-close" data-close-modal aria-label="إغلاق">' + ic('x') + '</button>' +
            '<h3 class="modal-title" id="verifyModalTitle">' + ic('lock') + ' سؤال التحقق من الملكية</h3>' +
            '<p class="modal-help">من وجد هذا الغرض وضع سؤالاً يعرف جوابه صاحبه فقط. أجب لتحصل على رقم التواصل.</p>' +
            '<p id="modalSecretQuestion" class="modal-question"></p>' +
            '<form id="verifyForm">' +
                '<div class="form-group">' +
                    '<label class="form-label" for="modalSecretAnswer">إجابتك</label>' +
                    '<input type="text" id="modalSecretAnswer" class="form-control" autocomplete="off" required>' +
                '</div>' +
                '<div id="modalVerifyResult" class="hidden"></div>' +
                '<div class="modal-actions">' +
                    '<button type="button" class="btn btn-outline-primary" data-close-modal>إلغاء</button>' +
                    '<button type="submit" class="btn btn-primary" id="modalVerifyBtn">تأكيد وإظهار الرقم</button>' +
                '</div>' +
            '</form>' +
        '</div>';
    document.body.appendChild(m);

    m.addEventListener('click', function(e) {
        if (e.target === m || e.target.closest('[data-close-modal]')) closeVerifyModal();
    });
    m.querySelector('#verifyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitVerify();
    });
    return m;
}

function openVerifyModal(itemId, question) {
    var m = ensureVerifyModal();
    verifyLastFocus = document.activeElement;
    m.setAttribute('data-item', itemId);
    m.querySelector('#modalSecretQuestion').textContent = question;
    m.querySelector('#modalSecretAnswer').value = '';
    var res = m.querySelector('#modalVerifyResult');
    res.className = 'hidden';
    res.innerHTML = '';
    m.classList.add('show');
    setTimeout(function() { m.querySelector('#modalSecretAnswer').focus(); }, 30);
}

function closeVerifyModal() {
    var m = document.getElementById('verifyModal');
    if (!m) return;
    m.classList.remove('show');
    if (verifyLastFocus && verifyLastFocus.focus) verifyLastFocus.focus();
}

function submitVerify() {
    var m = document.getElementById('verifyModal');
    var answer = m.querySelector('#modalSecretAnswer').value.trim();
    var res = m.querySelector('#modalVerifyResult');
    var btn = m.querySelector('#modalVerifyBtn');
    if (!answer) return;

    btn.disabled = true;
    postJSON('api/verify.php', {item_id: m.getAttribute('data-item'), answer: answer})
        .then(function(data) {
            btn.disabled = false;
            if (data.match) {
                res.className = 'alert alert-success';
                res.innerHTML = '<div style="width:100%"><div class="alert-title">إجابة صحيحة</div>' + contactHtml(data) + '</div>';
            } else {
                res.className = 'alert alert-danger';
                res.textContent = data.message || 'الإجابة غير صحيحة.';
            }
        })
        .catch(function() {
            btn.disabled = false;
            res.className = 'alert alert-danger';
            res.textContent = 'تعذر الاتصال بالإنترنت، حاول مرة أخرى.';
        });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeVerifyModal();
});

/* ---------- Button wiring ---------- */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-action');
    if (action === 'reveal') {
        e.preventDefault();
        revealContact(btn);
    } else if (action === 'verify') {
        e.preventDefault();
        openVerifyModal(btn.getAttribute('data-id'), btn.getAttribute('data-question'));
    } else if (action === 'share') {
        e.preventDefault();
        shareWhatsApp(btn.getAttribute('data-text'));
    } else if (action === 'copy') {
        e.preventDefault();
        copyText(btn, btn.getAttribute('data-text'));
    }
});

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function() {});
}
