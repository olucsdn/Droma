/**
 * Droma — Gerenciador de Armazenamento e Sincronização Offline (PWA)
 * Utiliza IndexedDB local para persistir despesas e receitas sem conexão
 * e as sincroniza atomicamente com a nuvem via /api/v1/sync.php assim que houver rede.
 */
(function(window) {
    'use strict';

    const DB_NAME = 'DromaOfflineDB';
    const DB_VERSION = 1;
    const STORE_PENDING = 'pending_transactions';
    const STORE_META = 'offline_metadata';

    class OfflineManager {
        constructor() {
            this.db = null;
            this._isSyncing = false;
            this._readyPromise = this._openDatabase();
        }

        /**
         * Inicializa o banco IndexedDB
         */
        _openDatabase() {
            return new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) {
                    console.warn('[Droma Offline] IndexedDB não suportado neste navegador.');
                    resolve(null);
                    return;
                }

                const request = indexedDB.open(DB_NAME, DB_VERSION);

                request.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(STORE_PENDING)) {
                        db.createObjectStore(STORE_PENDING, { keyPath: 'client_id' });
                    }
                    if (!db.objectStoreNames.contains(STORE_META)) {
                        db.createObjectStore(STORE_META, { keyPath: 'key' });
                    }
                };

                request.onsuccess = (e) => {
                    this.db = e.target.result;
                    resolve(this.db);
                };

                request.onerror = (e) => {
                    console.error('[Droma Offline] Erro ao abrir IndexedDB:', e.target.error);
                    resolve(null);
                };
            });
        }

        async _getStore(storeName, mode = 'readonly') {
            await this._readyPromise;
            if (!this.db) return null;
            const tx = this.db.transaction(storeName, mode);
            return tx.objectStore(storeName);
        }

        /**
         * Salva um lançamento pendente localmente no IndexedDB
         */
        async savePendingTransaction(transaction) {
            await this._readyPromise;

            const clientId = transaction.client_id || ('off_' + Date.now() + '_' + Math.random().toString(36).substr(2, 7));
            const record = {
                ...transaction,
                client_id: clientId,
                created_at_local: new Date().toISOString()
            };

            if (this.db) {
                await new Promise((resolve, reject) => {
                    try {
                        const tx = this.db.transaction(STORE_PENDING, 'readwrite');
                        const store = tx.objectStore(STORE_PENDING);
                        const req = store.put(record);
                        req.onsuccess = () => resolve(true);
                        req.onerror = () => reject(req.error);
                    } catch (e) {
                        reject(e);
                    }
                });
            } else {
                // Fallback para localStorage caso IndexedDB esteja inacessível
                const current = JSON.parse(localStorage.getItem('droma_fallback_pending') || '[]');
                current.push(record);
                localStorage.setItem('droma_fallback_pending', JSON.stringify(current));
            }

            this.updateBadge();
            this.showToast('Lançamento salvo no aparelho! Será sincronizado com a nuvem assim que a internet voltar. ⏳', 'warning', 5000);

            window.dispatchEvent(new CustomEvent('droma:pending-saved', { detail: record }));
            return clientId;
        }

        /**
         * Retorna todos os lançamentos que aguardam sincronização
         */
        async getPendingTransactions() {
            await this._readyPromise;
            if (this.db) {
                return new Promise((resolve) => {
                    try {
                        const tx = this.db.transaction(STORE_PENDING, 'readonly');
                        const store = tx.objectStore(STORE_PENDING);
                        const req = store.getAll();
                        req.onsuccess = () => resolve(req.result || []);
                        req.onerror = () => resolve([]);
                    } catch (e) {
                        resolve([]);
                    }
                });
            } else {
                return JSON.parse(localStorage.getItem('droma_fallback_pending') || '[]');
            }
        }

        /**
         * Conta quantos lançamentos estão pendentes
         */
        async countPending() {
            const list = await this.getPendingTransactions();
            return list.length;
        }

        /**
         * Remove lançamentos que já foram sincronizados com sucesso
         */
        async removePending(clientIds) {
            await this._readyPromise;
            const ids = Array.isArray(clientIds) ? clientIds : [clientIds];

            if (this.db) {
                await new Promise((resolve) => {
                    try {
                        const tx = this.db.transaction(STORE_PENDING, 'readwrite');
                        const store = tx.objectStore(STORE_PENDING);
                        ids.forEach(id => store.delete(id));
                        tx.oncomplete = () => resolve(true);
                        tx.onerror = () => resolve(false);
                    } catch (e) {
                        resolve(false);
                    }
                });
            } else {
                let current = JSON.parse(localStorage.getItem('droma_fallback_pending') || '[]');
                current = current.filter(item => !ids.includes(item.client_id));
                localStorage.setItem('droma_fallback_pending', JSON.stringify(current));
            }

            this.updateBadge();
        }

        /**
         * Guarda metadados essenciais (contas, categorias, cartões) para preencher a tela offline
         */
        async cacheMetadata(data) {
            try {
                localStorage.setItem('droma_cached_meta', JSON.stringify({
                    ...data,
                    cached_at: Date.now()
                }));
            } catch (e) {}
        }

        getCachedMetadata() {
            try {
                const raw = localStorage.getItem('droma_cached_meta');
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }

        /**
         * Executa a sincronização de todos os lançamentos locais com o servidor
         */
        async syncPending() {
            if (!navigator.onLine || this._isSyncing) return;

            const pending = await this.getPendingTransactions();
            if (!pending || pending.length === 0) {
                this.updateBadge();
                return;
            }

            this._isSyncing = true;
            this.showToast(`Sincronizando ${pending.length} lançamento(s) com a nuvem... 🔄`, 'info', 3000);

            try {
                const response = await fetch('api/v1/sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ transactions: pending })
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();
                if (result.success) {
                    const syncedIds = (result.data?.results || [])
                        .filter(r => r.status === 'synced')
                        .map(r => r.client_id);

                    if (syncedIds.length > 0) {
                        await this.removePending(syncedIds);
                        this.showToast(`✅ ${syncedIds.length} lançamento(s) sincronizado(s) com sucesso!`, 'success', 4000);
                        window.dispatchEvent(new CustomEvent('droma:synced', { detail: result.data }));

                        // Se estiver na tela inicial ou extrato, recarrega suavemente após 1.2s para refletir novos saldos
                        const path = window.location.pathname;
                        if (path.endsWith('/') || path.endsWith('index.php') || path.endsWith('statement') || path.endsWith('statement.php')) {
                            setTimeout(() => {
                                window.location.reload();
                            }, 1200);
                        }
                    }
                }
            } catch (err) {
                console.warn('[Droma Sync] Erro na sincronização, tentando mais tarde:', err);
            } finally {
                this._isSyncing = false;
                this.updateBadge();
            }
        }

        /**
         * Atualiza o badge/indicador visual de lançamentos pendentes
         */
        async updateBadge() {
            if (window.location.pathname.includes('offline.html')) {
                const existing = document.getElementById('dromaOfflineBadge');
                if (existing) existing.remove();
                return;
            }

            const count = await this.countPending();
            let badge = document.getElementById('dromaOfflineBadge');

            if (count > 0 || !navigator.onLine) {
                if (!badge) {
                    badge = document.createElement('div');
                    badge.id = 'dromaOfflineBadge';
                    badge.className = 'droma-offline-badge';
                    document.body.appendChild(badge);
                }

                if (!navigator.onLine) {
                    badge.innerHTML = `
                        <span class="dot-offline"></span>
                        <span>Modo Offline ${count > 0 ? `(${count} salvo)` : ''}</span>
                    `;
                    badge.classList.add('offline');
                } else if (count > 0) {
                    badge.innerHTML = `
                        <span class="dot-syncing"></span>
                        <span>Sincronizando ${count} pendente(s)...</span>
                    `;
                    badge.classList.remove('offline');
                }
                badge.style.display = 'flex';
            } else if (badge) {
                badge.style.display = 'none';
            }
        }

        /**
         * Exibe toasts elegantes e padronizados
         */
        showToast(message, type = 'info', duration = 4000) {
            let container = document.getElementById('dromaToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'dromaToastContainer';
                container.className = 'droma-toast-container';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `droma-toast droma-toast-${type}`;

            let iconSvg = '';
            if (type === 'success') {
                iconSvg = `<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`;
            } else if (type === 'warning') {
                iconSvg = `<svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`;
            } else {
                iconSvg = `<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>`;
            }

            toast.innerHTML = `
                <div class="droma-toast-icon">${iconSvg}</div>
                <div class="droma-toast-msg">${message}</div>
                <button type="button" class="droma-toast-close">&times;</button>
            `;

            toast.querySelector('.droma-toast-close').addEventListener('click', () => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 250);
            });

            container.appendChild(toast);

            setTimeout(() => {
                if (toast.parentElement) {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 250);
                }
            }, duration);
        }

        /**
         * Inicialização global
         */
        init() {
            // Monitora reconexão e perda de rede
            window.addEventListener('online', () => {
                this.updateBadge();
                this.showToast('Internet reconectada! Iniciando sincronização... 🌐', 'info', 3000);
                this.syncPending();
            });

            window.addEventListener('offline', () => {
                this.updateBadge();
                this.showToast('Você está offline. Lançamentos serão salvos no aparelho. 📡', 'warning', 4000);
            });

            // Verifica pendências ao carregar
            if (navigator.onLine) {
                setTimeout(() => this.syncPending(), 1500);
            }
            this.updateBadge();
        }
    }

    // Exporta instância única global
    window.DromaOffline = new OfflineManager();

    // Inicia quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.DromaOffline.init());
    } else {
        window.DromaOffline.init();
    }

})(window);
