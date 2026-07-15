/**
 * VoiceCall Custom Dialogs
 * Replaces native window.alert and window.confirm with Tailwind-styled Toast and Modals.
 */

(function() {
    // 1. Inject CSS for animations
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
    `;
    document.head.appendChild(style);

    // 2. Container for Toasts
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'fixed bottom-4 right-4 z-[9999] flex flex-col gap-2';
        document.body.appendChild(toastContainer);
    }

    /**
     * Show Toast Notification
     * @param {string} message 
     * @param {string} type 'success', 'error', 'info', 'warning'
     * @param {number} duration ms
     */
    window.showToast = function(message, type = 'info', duration = 3000) {
        const colors = {
            success: 'bg-emerald-500 text-white',
            error: 'bg-red-500 text-white',
            warning: 'bg-amber-500 text-white',
            info: 'bg-blue-500 text-white'
        };
        const icons = {
            success: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>',
            error: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>',
            warning: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
            info: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
        };

        const toast = document.createElement('div');
        toast.className = `flex items-start gap-3 px-4 py-3 rounded-lg shadow-lg text-sm font-medium toast-enter ${colors[type]} max-w-sm`;
        toast.innerHTML = `
            ${icons[type]}
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
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 z-[10000] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm modal-overlay-enter p-4';
            
            const iconColors = {
                success: 'text-emerald-500 bg-emerald-50',
                error: 'text-red-500 bg-red-50',
                warning: 'text-amber-500 bg-amber-50',
                info: 'text-blue-500 bg-blue-50'
            };
            
            const icons = {
                success: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>',
                error: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>',
                warning: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
                info: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            };

            const content = document.createElement('div');
            content.className = 'bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden modal-content-enter';
            content.innerHTML = `
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center ${iconColors[type]}">
                            ${icons[type]}
                        </div>
                        <div class="flex-1 pt-1">
                            <h3 class="text-lg font-bold text-slate-800">${title}</h3>
                            <p class="text-sm text-slate-600 mt-2 max-h-60 overflow-y-auto" style="white-space: pre-wrap;">${message}</p>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 border-t border-slate-100 flex justify-end gap-3">
                    ${isConfirm ? `<button id="vc-modal-cancel" class="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200 rounded-lg transition-colors">ยกเลิก</button>` : ''}
                    <button id="vc-modal-confirm" class="px-5 py-2 text-sm font-semibold text-white rounded-lg transition-colors ${type === 'error' ? 'bg-red-500 hover:bg-red-600' : 'bg-blue-500 hover:bg-blue-600'}">
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
