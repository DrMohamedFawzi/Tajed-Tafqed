'use strict';

// API base path
const API = './api';

// Search functionality
function initSearch() {
    const searchInput = document.getElementById('searchInput');
    const searchBtn = document.getElementById('searchBtn');
    const resultsDiv = document.getElementById('searchResults');
    
    if (!searchInput) return;
    
    // Search on button click
    searchBtn.addEventListener('click', () => performSearch(searchInput.value));
    
    // Search on Enter key
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') performSearch(searchInput.value);
    });
    
    // Debounced auto-search after 500ms of typing (only for 4+ chars)
    let timeout;
    searchInput.addEventListener('input', () => {
        clearTimeout(timeout);
        if (searchInput.value.length >= 4) {
            timeout = setTimeout(() => performSearch(searchInput.value), 500);
        }
    });
}

async function performSearch(query) {
    query = query.trim();
    if (!query) return;
    
    const resultsDiv = document.getElementById('searchResults');
    const searchBtn = document.getElementById('searchBtn');
    
    // Show loading
    searchBtn.innerHTML = '<span class="spinner"></span>';
    searchBtn.disabled = true;
    resultsDiv.classList.remove('hidden');
    resultsDiv.innerHTML = '<div class="text-center mt-2"><span class="spinner" style="border-color: rgba(13,148,136,0.3); border-top-color: #0d9488;"></span> جاري البحث...</div>';
    
    try {
        const res = await fetch(`${API}/search.php?q=${encodeURIComponent(query)}`);
        const data = await res.json();
        
        if (data.success && data.count > 0) {
            resultsDiv.innerHTML = renderResults(data.results);
        } else if (data.has_radar) {
            resultsDiv.innerHTML = `
                <div class="radar-alert">
                    <h3>📡 أنت مسجل في الرادار</h3>
                    <p>سنبلغك فوراً عند العثور على غرضك</p>
                </div>`;
        } else {
            resultsDiv.innerHTML = renderNoResults(query, data);
        }
    } catch (err) {
        resultsDiv.innerHTML = '<div class="result-card text-center">⚠️ خطأ في الاتصال. حاول مرة أخرى.</div>';
    }
    
    searchBtn.innerHTML = 'ابحث الآن';
    searchBtn.disabled = false;
}

function renderResults(results) {
    let html = `<h3 class="mt-2 mb-2">📋 نتائج البحث (${results.length})</h3>`;
    
    results.forEach(item => {
        const badgeClass = item.type === 'lost' ? 'badge-lost' : 'badge-found';
        const badgeText = item.type_label;
        const borderClass = item.type;
        
        html += `
        <div class="result-card ${borderClass}">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                <span class="${badgeClass}">${badgeText}</span>
                <span style="color: var(--text-muted); font-size:0.85rem;">${item.time_ago}</span>
            </div>
            <h4 style="margin: 0.75rem 0 0.25rem; font-size:1.1rem;">${escapeHtml(item.title)}</h4>
            <p style="color: var(--text-muted); margin: 0.25rem 0;">📍 ${escapeHtml(item.location)} ${item.category_label ? '• ' + item.category_label : ''}</p>
            ${item.drop_point ? `<p style="color: var(--success); font-weight:600;">📦 مكان التسليم: ${escapeHtml(item.drop_point)}</p>` : ''}
            <div style="display:flex; gap:0.5rem; margin-top:0.75rem; flex-wrap:wrap;">
                ${item.has_secret_question ? `<button class="btn-secondary" onclick="showVerifyModal(${item.id}, '${escapeJs(item.secret_question)}')">🔐 التحقق من الملكية</button>` : ''}
                ${item.contact_visible ? `<a href="tel:${item.contact_info}" class="btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.9rem;">📞 ${item.contact_info}</a>` : ''}
                <button class="btn-whatsapp" onclick="copyWhatsApp('${escapeJs(item.whatsapp_text)}')">📋 نسخ للواتساب</button>
            </div>
        </div>`;
    });
    
    return html;
}

function renderNoResults(query, data) {
    return `
    <div class="radar-alert">
        <h3>😔 لم يُعثر على نتائج</h3>
        <p>${data.radar_message || 'لم نجد ما يطابق بحثك'}</p>
        <div class="mt-3" id="radarForm">
            <h4>📡 سجّل في الرادار العكسي</h4>
            <p style="font-size:0.9rem; color: var(--text-muted);">سنبلغك فوراً عند العثور على غرضك</p>
            <div class="form-group mt-2">
                <input type="tel" id="radarPhone" class="form-control" placeholder="رقم جوالك" dir="ltr">
            </div>
            <div class="form-group">
                <input type="tel" id="radarAltPhone" class="form-control" placeholder="رقم بديل (قريب أو صديق)" dir="ltr">
            </div>
            <button class="btn-primary" onclick="subscribeRadar('${escapeJs(query)}')">📡 سجلني في الرادار</button>
        </div>
    </div>`;
}

// Radar subscription
async function subscribeRadar(query) {
    const phone = document.getElementById('radarPhone').value.trim();
    const altPhone = document.getElementById('radarAltPhone').value.trim();
    
    if (!phone) {
        alert('الرجاء إدخال رقم الجوال');
        return;
    }
    
    try {
        const res = await fetch(`${API}/radar.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `identifier=${encodeURIComponent(query)}&phone=${encodeURIComponent(phone)}&alt_phone=${encodeURIComponent(altPhone)}`
        });
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('radarForm').innerHTML = `
                <div class="match-alert" style="background: #dcfce7; border-color: #22c55e;">
                    <h3>✅ ${data.message}</h3>
                </div>`;
        } else {
            alert(data.message || 'حدث خطأ');
        }
    } catch (err) {
        alert('خطأ في الاتصال');
    }
}

// Verify modal
function showVerifyModal(itemId, question) {
    const modal = document.getElementById('verifyModal');
    document.getElementById('verifyQuestion').textContent = question;
    document.getElementById('verifyItemId').value = itemId;
    document.getElementById('verifyAnswer').value = '';
    document.getElementById('verifyResult').innerHTML = '';
    modal.classList.add('active');
}

function closeVerifyModal() {
    document.getElementById('verifyModal').classList.remove('active');
}

async function submitVerify() {
    const itemId = document.getElementById('verifyItemId').value;
    const answer = document.getElementById('verifyAnswer').value.trim();
    const resultDiv = document.getElementById('verifyResult');
    
    if (!answer) {
        resultDiv.innerHTML = '<p style="color: var(--danger);">الرجاء إدخال الإجابة</p>';
        return;
    }
    
    try {
        const res = await fetch(`${API}/verify.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `item_id=${itemId}&answer=${encodeURIComponent(answer)}`
        });
        const data = await res.json();
        
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="match-alert">
                    <h3>${data.message}</h3>
                    <p style="font-size:1.3rem; font-weight:700; direction:ltr; margin-top:1rem;">📞 ${data.contact_info}</p>
                    ${data.contact_alt ? `<p style="direction:ltr;">📞 بديل: ${data.contact_alt}</p>` : ''}
                </div>`;
        } else {
            resultDiv.innerHTML = `<p style="color: var(--danger); font-weight:600;">${data.message}</p>`;
        }
    } catch (err) {
        resultDiv.innerHTML = '<p style="color: var(--danger);">خطأ في الاتصال</p>';
    }
}

// Tag lookup
function initTagLookup() {
    const tagInput = document.getElementById('tagInput');
    const tagBtn = document.getElementById('tagLookupBtn');
    
    if (!tagInput) return;
    
    tagBtn.addEventListener('click', () => performSearch('GZ-' + tagInput.value.replace(/^GZ-/i, '')));
}

// Copy to WhatsApp
function copyWhatsApp(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('✅ تم النسخ! الصقه في الواتساب');
        });
    } else {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('✅ تم النسخ!');
    }
}

// Resolve item
async function resolveItem(itemId) {
    if (!confirm('هل أنت متأكد أنك استلمت الغرض؟ سيتم حذف بيانات التواصل نهائياً.')) return;
    
    try {
        const res = await fetch(`${API}/resolve.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `item_id=${itemId}`
        });
        const data = await res.json();
        
        if (data.success) {
            showToast(data.message);
            // Update counter if visible
            const counter = document.getElementById('successCount');
            if (counter) counter.textContent = data.new_count;
        }
    } catch (err) {
        alert('خطأ في الاتصال');
    }
}

// Poster generation
function generatePoster() {
    const area = document.getElementById('posterArea').value;
    if (!area) {
        alert('اختر المنطقة أولاً');
        return;
    }
    window.open(`${API}/poster.php?area=${encodeURIComponent(area)}`, '_blank');
}

// Toast notification
function showToast(message) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1e293b;color:white;padding:12px 24px;border-radius:50px;font-family:Tajawal;z-index:9999;animation:fadeIn 0.3s;';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// Utility functions
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escapeJs(str) {
    if (!str) return '';
    return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// Form handling for report.php
function initReportForm() {
    const typeSelect = document.getElementById('reportType');
    if (!typeSelect) return;
    
    // Show/hide conditional fields
    typeSelect.addEventListener('change', toggleFormSections);
    
    // Drop point toggle
    const dropCheck = document.getElementById('dropPointCheck');
    if (dropCheck) {
        dropCheck.addEventListener('change', () => {
            document.getElementById('dropPointSection').classList.toggle('hidden', !dropCheck.checked);
        });
    }
}

function toggleFormSections() {
    // Show/hide sections based on form type
    const type = new URLSearchParams(window.location.search).get('type');
    document.querySelectorAll('[data-show-for]').forEach(el => {
        const showFor = el.dataset.showFor.split(',');
        el.classList.toggle('hidden', !showFor.includes(type));
    });
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initSearch();
    initTagLookup();
    initReportForm();
    toggleFormSections();
});
