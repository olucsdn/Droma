/**
 * Droma Financy — Medidor Visual de Força de Senha em Tempo Real
 * Compatível com OWASP, NIST e Política de Segurança Droma
 */
(function () {
    'use strict';

    function initPasswordMeter(input) {
        if (!input) return;

        // Identifica o contêiner do medidor
        var targetId = input.getAttribute('data-meter-target');
        var meterWrap = null;

        if (targetId) {
            meterWrap = document.getElementById(targetId);
        }

        if (!meterWrap && input.id) {
            meterWrap = document.getElementById('password-meter-' + input.id);
        }

        if (!meterWrap) {
            // Procura nos elementos irmãos ou no contêiner pai mais próximo
            var parent = input.closest('.senha-input') || input.parentElement;
            if (parent) {
                meterWrap = parent.querySelector('.password-meter-wrap') || parent.nextElementSibling;
                if (meterWrap && !meterWrap.classList.contains('password-meter-wrap')) {
                    meterWrap = parent.parentElement.querySelector('.password-meter-wrap');
                }
            }
        }

        if (!meterWrap || !meterWrap.classList.contains('password-meter-wrap')) {
            return;
        }

        var fill = meterWrap.querySelector('.password-meter-fill');
        var text = meterWrap.querySelector('.password-meter-text');
        var ruleLength = meterWrap.querySelector('[data-rule="length"]');
        var ruleUpper = meterWrap.querySelector('[data-rule="upper"]');
        var ruleLower = meterWrap.querySelector('[data-rule="lower"]');
        var ruleNumber = meterWrap.querySelector('[data-rule="number"]');

        function updateMeter() {
            var pwd = input.value || '';

            if (pwd.length === 0) {
                meterWrap.classList.remove('active');
                if (fill) {
                    fill.style.width = '0%';
                    fill.className = 'password-meter-fill';
                }
                if (text) {
                    text.textContent = 'Mínimo de 8 caracteres';
                    text.className = 'password-meter-text';
                }
                if (ruleLength) ruleLength.classList.remove('valid');
                if (ruleUpper) ruleUpper.classList.remove('valid');
                if (ruleLower) ruleLower.classList.remove('valid');
                if (ruleNumber) ruleNumber.classList.remove('valid');
                return;
            }

            meterWrap.classList.add('active');

            var hasLength = pwd.length >= 8;
            var hasUpper = /[A-Z]/.test(pwd);
            var hasLower = /[a-z]/.test(pwd);
            var hasNumber = /[0-9]/.test(pwd);
            var hasSpecial = /[^A-Za-z0-9]/.test(pwd);

            if (ruleLength) ruleLength.classList.toggle('valid', hasLength);
            if (ruleUpper) ruleUpper.classList.toggle('valid', hasUpper);
            if (ruleLower) ruleLower.classList.toggle('valid', hasLower);
            if (ruleNumber) ruleNumber.classList.toggle('valid', hasNumber);

            var score = 0;
            if (hasLength) score++;
            if (hasUpper) score++;
            if (hasLower) score++;
            if (hasNumber) score++;
            if (hasSpecial || (hasLength && pwd.length >= 12)) score++;

            var width = '20%';
            var levelClass = 'muito-fraca';
            var levelText = 'Muito fraca';

            if (score <= 1) {
                width = '20%';
                levelClass = 'muito-fraca';
                levelText = 'Muito fraca';
            } else if (score === 2) {
                width = '40%';
                levelClass = 'fraca';
                levelText = 'Fraca';
            } else if (score === 3) {
                width = '60%';
                levelClass = 'media';
                levelText = 'Média';
            } else if (score === 4) {
                width = '85%';
                levelClass = 'forte';
                levelText = 'Forte';
            } else if (score >= 5) {
                width = '100%';
                levelClass = 'excelente';
                levelText = 'Excelente';
            }

            if (fill) {
                fill.style.width = width;
                fill.className = 'password-meter-fill ' + levelClass;
            }
            if (text) {
                text.textContent = levelText;
                text.className = 'password-meter-text ' + levelClass;
            }
        }

        input.addEventListener('input', updateMeter);
        input.addEventListener('focus', function () {
            if (input.value.length > 0) {
                meterWrap.classList.add('active');
            }
        });

        // Executa caso o campo já venha preenchido
        if (input.value.length > 0) {
            updateMeter();
        }
    }

    function initAll() {
        var inputs = document.querySelectorAll('[data-password-meter]');
        for (var i = 0; i < inputs.length; i++) {
            initPasswordMeter(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
