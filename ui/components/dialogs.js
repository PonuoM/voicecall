/**
 * VoiceCall Custom Dialogs
 * Replaces native window.alert and window.confirm with styled Toasts and Modals.
 *
 * Self-contained on purpose: all styling is injected here as vc-* classes, because this file
 * is shared by pages WITH Tailwind (index.html) and WITHOUT it (ui/sync_dashboard.html).
 * An earlier version used Tailwind utility classes — on the sync page they matched nothing,
 * so every alert() rendered as giant unstyled text at the bottom of the page.
 */

(function() {
    // 1. Inject all dialog CSS (animations + layout — no external framework dependency)
    const style = document.createElement('style');
    style.innerHTML = `
        @keyframes toast-slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes toast-slide-out {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .toast-enter { animation: toast-slide-in 0.3s ease-out forwards; }
        .toast-exit { animation: toast-slide-out 0.3s ease-in forwards; }

        @keyframes modal-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes modal-scale-in {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-overlay-enter { animation: modal-fade-in 0.2s ease-out forwards; }
        .modal-content-enter { animation: modal-scale-in 0.2s ease-out forwards; }

        #toast-container {
            position: fixed; bottom: 16px; right: 16px; z-index: 9999;
            display: flex; flex-direction: column; gap: 8px;
        }
        .vc-toast {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 16px; border-radius: 8px; max-width: 24rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.15);
            font-size: 14px; font-weight: 500; color: #fff;
        }
        .vc-toast svg { width: 20px; height: 20px; flex-shrink: 0; }
        .vc-toast--success { background: #10b981; }
        .vc-toast--error   { background: #ef4444; }
        .vc-toast--warning { background: #f59e0b; }
        .vc-toast--info    { background: #3b82f6; }

        .vc-modal-overlay {
            position: fixed; inset: 0; z-index: 10000;
            display: flex; align-items: center; justify-content: center;
            background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px);
            padding: 16px;
        }
        .vc-modal {
            background: #fff; border-radius: 16px; overflow: hidden;
            width: 100%; max-width: 24rem;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            font-family: inherit;
        }
        .vc-modal-body { padding: 24px; display: flex; align-items: flex-start; gap: 16px; }
        .vc-modal-icon {
            flex-shrink: 0; width: 48px; height: 48px; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
        }
        .vc-modal-icon svg { width: 24px; height: 24px; }
        .vc-modal-icon--success { color: #10b981; background: #ecfdf5; }
        .vc-modal-icon--error   { color: #ef4444; background: #fef2f2; }
        .vc-modal-icon--warning { color: #f59e0b; background: #fffbeb; }
        .vc-modal-icon--info    { color: #3b82f6; background: #eff6ff; }
        .vc-modal-title { margin: 4px 0 0; font-size: 18px; font-weight: 700; color: #1e293b; }
        .vc-modal-msg {
            margin: 8px 0 0; font-size: 14px; color: #475569; line-height: 1.5;
            max-height: 240px; overflow-y: auto; white-space: pre-wrap; word-break: break-word;
        }
        .vc-modal-footer {
            background: #f8fafc; padding: 16px 24px; border-top: 1px solid #f1f5f9;
            display: flex; justify-content: flex-end; gap: 12px;
        }
        .vc-btn {
            border: none; cursor: pointer; border-radius: 8px;
            font-size: 14px; font-family: inherit; transition: background-color 0.15s;
        }
        .vc-btn--ghost { padding: 8px 16px; font-weight: 500; color: #475569; background: transparent; }
        .vc-btn--ghost:hover { background: #e2e8f0; }
        .vc-btn--primary { padding: 8px 20px; font-weight: 600; color: #fff; background: #3b82f6; }
        .vc-btn--primary:hover { background: #2563eb; }
        .vc-btn--danger { padding: 8px 20px; font-weight: 600; color: #fff; background: #ef4444; }
        .vc-btn--danger:hover { background: #dc2626; }
    `;
    document.head.appendChild(style);

    // 2. Container for Toasts
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        document.body.appendChild(toastContainer);
    }

    const ICONS = {
        success: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>',
        error: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>',
        warning: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
        info: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
    };

    /**
     * Show Toast Notification
     * @param {string} message
     * @param {string} type 'success', 'error', 'info', 'warning'
     * @param {number} duration ms
     */
    window.showToast = function(message, type = 'info', duration = 3000) {
        if (!ICONS[type]) type = 'info';
        const toast = document.createElement('div');
        toast.className = `vc-toast vc-toast--${type} toast-enter`;
        toast.innerHTML = `
            ${ICONS[type]}
            <div style="white-space: pre-wrap; word-break: break-word; line-height: 1.4;">${message}</div>
        `;

        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.classList.remove('toast-enter');
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 300); // Wait for animation
        }, duration);
    };

    /**
     * Show Modal (Alert or Confirm)
     * @returns {Promise<boolean>}
     */
    function createModal(title, message, isConfirm = false, type = 'info') {
        return new Promise((resolve) => {
            if (!ICONS[type]) type = 'info';
            const overlay = document.createElement('div');
            overlay.className = 'vc-modal-overlay modal-overlay-enter';

            const content = document.createElement('div');
            content.className = 'vc-modal modal-content-enter';
            content.innerHTML = `
                <div class="vc-modal-body">
                    <div class="vc-modal-icon vc-modal-icon--${type}">
                        ${ICONS[type]}
                    </div>
                    <div style="flex: 1;">
                        <h3 class="vc-modal-title">${title}</h3>
                        <p class="vc-modal-msg">${message}</p>
                    </div>
                </div>
                <div class="vc-modal-footer">
                    ${isConfirm ? `<button id="vc-modal-cancel" class="vc-btn vc-btn--ghost">ยกเลิก</button>` : ''}
                    <button id="vc-modal-confirm" class="vc-btn ${type === 'error' ? 'vc-btn--danger' : 'vc-btn--primary'}">
                        ${isConfirm ? 'ยืนยัน' : 'ตกลง'}
                    </button>
                </div>
            `;

            overlay.appendChild(content);
            document.body.appendChild(overlay);

            const close = (result) => {
                overlay.classList.remove('modal-overlay-enter');
                content.classList.remove('modal-content-enter');
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                    resolve(result);
                }, 200);
            };

            if (isConfirm) {
                content.querySelector('#vc-modal-cancel').addEventListener('click', () => close(false));
            }
            content.querySelector('#vc-modal-confirm').addEventListener('click', () => close(true));
        });
    }

    /**
     * Show Alert Modal
     */
    window.showAlertModal = function(title, message, type = 'info') {
        return createModal(title, message, false, type);
    };

    /**
     * Show Confirm Modal
     */
    window.showConfirmModal = function(title, message, type = 'warning') {
        return createModal(title, message, true, type);
    };

    // Override native alert to use Modal
    window.alert = function(msg) {
        let type = 'info';
        if (msg.toString().toLowerCase().includes('error') || msg.toString().toLowerCase().includes('fail') || msg.toString().includes('ข้อผิดพลาด') || msg.toString().includes('ไม่พบ')) {
            type = 'error';
        } else if (msg.toString().toLowerCase().includes('success') || msg.toString().includes('สำเร็จ') || msg.toString().includes('เสร็จ')) {
            type = 'success';
        }
        window.showAlertModal('ข้อความระบบ', msg, type);
    };

})();
