// УНЭП-подписание в браузере (Фаза 4, T4.1).
// Контракт §3.5 плана e_signature_plan.md: подписывается HEX-СТРОКА SHA-256 хэша
// канонического PDF как ASCII-байты, RSA-SHA256 (PKCS#1 v1.5). Ровно это проверяет
// сервер через openssl_verify. Приватный ключ и пароль контейнера не покидают браузер.
import forge from 'node-forge';

function normalizeSerial(serial) {
    return String(serial || '').replace(/^0+/, '').toUpperCase();
}

function firstBag(p12, bagType) {
    const bags = p12.getBags({ bagType })[bagType] || [];
    return bags.length > 0 ? bags[0] : null;
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('enhanced-sign-form');
    if (!form) {
        return;
    }

    const errorBox = document.getElementById('enhanced-sign-error');
    const submitBtn = form.querySelector('button[type="submit"]');
    const fileInput = form.querySelector('input[name="p12_file"]');
    const passwordInput = form.querySelector('input[name="container_password"]');
    const documentHash = form.dataset.documentHash;
    const certificates = JSON.parse(form.dataset.certificates || '[]');

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.classList.add('d-none');

        const file = fileInput.files[0];
        if (!file) {
            showError('Выберите файл ключа (.p12).');
            return;
        }
        if (certificates.length === 0) {
            showError('У вас нет активных сертификатов. Обратитесь к администратору за выпуском ключа.');
            return;
        }

        submitBtn.disabled = true;
        let privateKey = null;
        try {
            const buffer = await file.arrayBuffer();
            const der = forge.util.createBuffer(new Uint8Array(buffer));

            let p12;
            try {
                p12 = forge.pkcs12.pkcs12FromAsn1(forge.asn1.fromDer(der), passwordInput.value);
            } catch (e) {
                showError('Не удалось открыть контейнер: файл не является .p12 или неверный пароль контейнера.');
                return;
            }

            const keyBag = firstBag(p12, forge.pki.oids.pkcs8ShroudedKeyBag) || firstBag(p12, forge.pki.oids.keyBag);
            if (!keyBag || !keyBag.key) {
                showError('В контейнере не найден приватный ключ.');
                return;
            }
            privateKey = keyBag.key;

            // Выбор сертификата: сверяем серийник сертификата из .p12 со списком активных.
            const certBag = firstBag(p12, forge.pki.oids.certBag);
            let certificateId = null;
            if (certBag && certBag.cert) {
                const serial = normalizeSerial(certBag.cert.serialNumber);
                const match = certificates.find((c) => normalizeSerial(c.serialNumber) === serial);
                if (match) {
                    certificateId = match.id;
                }
            } else if (certificates.length === 1) {
                // контейнер без сертификата — единственный активный берём как есть
                certificateId = certificates[0].id;
            }
            if (certificateId === null) {
                showError('Сертификат из файла не найден среди ваших активных сертификатов. Возможно, он отозван или истёк.');
                return;
            }

            // Контракт: hex-строка хэша подписывается как ASCII-байты.
            const md = forge.md.sha256.create();
            md.update(documentHash, 'utf8');
            const signature = forge.util.encode64(privateKey.sign(md));

            const body = new URLSearchParams();
            body.set('certificateId', String(certificateId));
            body.set('signature', signature);
            body.set('_token', form.querySelector('input[name="_token"]').value);

            const response = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            showError(data.message || 'Подпись отклонена сервером.');
        } catch (e) {
            showError('Не удалось подписать документ: ' + (e && e.message ? e.message : 'неизвестная ошибка.'));
        } finally {
            // обнуляем ссылки на ключ и пароль после подписания
            privateKey = null;
            passwordInput.value = '';
            fileInput.value = '';
            submitBtn.disabled = false;
        }
    });
});
