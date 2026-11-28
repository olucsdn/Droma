/**
 * Droma — Máscara Monetária Bancária (Estilo Nubank / ATM)
 * 
 * Comportamento:
 * - Inicia em "R$ 0,00".
 * - Insere dígitos da direita para a esquerda, mantendo 2 casas decimais fixas.
 * - Formata automaticamente centavos, milhares e símbolo monetário (R$).
 * - Backspace e Delete removem o último dígito pela direita.
 * - Sincroniza em tempo real com o valor normalizado (ex: "1234.56") para envio ao backend.
 */
(function(window) {
    'use strict';

    /**
     * Formata uma string de dígitos numéricos para o padrão monetário BRL (R$ 1.234,56)
     */
    function formatBRL(digitsOnly) {
        const digits = String(digitsOnly || '').replace(/\D/g, '').replace(/^0+/, '') || '0';
        if (digits === '0') {
            return { formatted: 'R$ 0,00', raw: '0.00', value: 0, digits: '' };
        }
        const padded = digits.padStart(3, '0');
        const integerPart = padded.slice(0, -2);
        const decimalPart = padded.slice(-2);
        const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const formatted = `R$ ${formattedInteger},${decimalPart}`;
        const raw = `${integerPart}.${decimalPart}`;
        return { formatted, raw, value: parseFloat(raw), digits };
    }

    /**
     * Inicializa a máscara bancária em um campo de entrada
     */
    function initMoneyMask(displayInput, hiddenInput) {
        if (!displayInput) return;

        // Se hiddenInput não foi fornecido diretamente, busca no formulário pelo targetName
        if (!hiddenInput) {
            const form = displayInput.form;
            const targetName = displayInput.dataset.targetName || 'amount';
            if (form) {
                hiddenInput = form.querySelector(`input[type="hidden"][name="${targetName}"]`);
            }
        }

        // Estado interno com a cadeia de dígitos brutos (em centavos)
        let rawDigits = '';

        // Carrega valor inicial (útil para edição ou valores prévios)
        const initialVal = hiddenInput?.value || displayInput.dataset.initialValue || displayInput.value || '';
        if (initialVal) {
            let cleanInit = String(initialVal).replace(/\s/g, '').replace('R$', '');
            if (cleanInit.includes('.') && cleanInit.includes(',')) {
                cleanInit = cleanInit.replace(/\./g, '').replace(',', '.');
            } else if (cleanInit.includes(',')) {
                cleanInit = cleanInit.replace(',', '.');
            }
            const num = parseFloat(cleanInit);
            if (!isNaN(num) && num > 0) {
                rawDigits = String(Math.round(num * 100));
            }
        }

        function updateInputs() {
            const res = formatBRL(rawDigits);
            displayInput.value = res.formatted;
            if (hiddenInput) {
                hiddenInput.value = res.raw;
            }
            displayInput.dataset.rawValue = res.raw;
        }

        // Garante os atributos de acessibilidade e digitação móvel adequada
        displayInput.setAttribute('type', 'text');
        displayInput.setAttribute('inputmode', 'numeric');
        displayInput.setAttribute('autocomplete', 'off');
        displayInput.setAttribute('spellcheck', 'false');

        // Renderiza o valor inicial formatado (R$ 0,00 ou o valor carregado)
        updateInputs();

        // Posiciona o cursor sempre no final do texto
        function setCursorToEnd() {
            setTimeout(() => {
                try {
                    const len = displayInput.value.length;
                    displayInput.setSelectionRange(len, len);
                } catch (e) {}
            }, 0);
        }

        displayInput.addEventListener('focus', setCursorToEnd);
        displayInput.addEventListener('click', setCursorToEnd);

        // Tratamento de teclas físicas e virtuais
        displayInput.addEventListener('keydown', (e) => {
            // Teclas de controle permitidas: Tab, Enter, Escape, setas direcionais, atalhos Ctrl/Cmd
            if (['Tab', 'Enter', 'Escape', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key) ||
                e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }

            // Backspace ou Delete: remove o último dígito pela direita
            if (e.key === 'Backspace' || e.key === 'Delete') {
                e.preventDefault();
                rawDigits = rawDigits.slice(0, -1);
                updateInputs();
                setCursorToEnd();
                return;
            }

            // Teclas numéricas 0 a 9
            if (/^[0-9]$/.test(e.key)) {
                e.preventDefault();
                // Limite razoável de até 11 dígitos (R$ 999.999.999,99)
                if (rawDigits.length >= 11) return;

                // Evita zeros à esquerda redundantes quando vazio
                if (rawDigits === '' && e.key === '0') return;

                rawDigits += e.key;
                updateInputs();
                setCursorToEnd();
                return;
            }

            // Bloqueia qualquer outro caractere (letras, vírgulas, pontos)
            // O usuário não precisa manipular pontuações manualmente
            e.preventDefault();
        });

        // Suporte a teclados móveis de dispositivos virtuais (Android/iOS)
        displayInput.addEventListener('input', (e) => {
            const curVal = displayInput.value;
            const digits = curVal.replace(/\D/g, '').replace(/^0+/, '');
            rawDigits = digits.slice(0, 11);
            updateInputs();
            setCursorToEnd();
        });

        // Suporte para colar valores (Ctrl+V)
        displayInput.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            let clean = text.replace(/\s/g, '').replace('R$', '');
            if (clean.includes('.') && clean.includes(',')) {
                clean = clean.replace(/\./g, '').replace(',', '.');
            } else if (clean.includes(',')) {
                clean = clean.replace(',', '.');
            }
            const num = parseFloat(clean);
            if (!isNaN(num) && num > 0) {
                rawDigits = String(Math.round(num * 100)).slice(0, 11);
            } else {
                const digits = text.replace(/\D/g, '').replace(/^0+/, '');
                rawDigits = digits.slice(0, 11);
            }
            updateInputs();
            setCursorToEnd();
        });

        // Método utilitário para reset programático (ex: após salvar lançamento offline)
        displayInput.resetMoneyMask = function(newValue = '') {
            rawDigits = '';
            if (newValue) {
                const num = parseFloat(String(newValue).replace(',', '.'));
                if (!isNaN(num) && num > 0) {
                    rawDigits = String(Math.round(num * 100));
                }
            }
            updateInputs();
        };

        // Validação preventiva no envio do formulário
        if (displayInput.form) {
            displayInput.form.addEventListener('submit', (e) => {
                const rawVal = hiddenInput ? hiddenInput.value : displayInput.dataset.rawValue;
                const val = parseFloat(rawVal);
                if (isNaN(val) || val <= 0) {
                    e.preventDefault();
                    if (window.DromaOffline?.showToast) {
                        window.DromaOffline.showToast('Por favor, informe um valor maior que R$ 0,00.', 'warning');
                    } else {
                        alert('Por favor, informe um valor maior que R$ 0,00.');
                    }
                    displayInput.focus();
                }
            });
        }
    }

    /**
     * Auto-inicializa todos os inputs marcados com data-money-mask ou classe .money-mask
     */
    function autoInit() {
        document.querySelectorAll('input[data-money-mask], input.money-mask').forEach(input => {
            if (!input._moneyMaskInitialized) {
                input._moneyMaskInitialized = true;
                initMoneyMask(input);
            }
        });
    }

    window.DromaMoneyMask = {
        formatBRL,
        init: initMoneyMask,
        autoInit
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }
})(window);
