<script setup lang="ts">
import LegalDocument from '@/components/LegalDocument.vue'
import { LEGAL_DOCUMENTS } from '@/legal/documents'
</script>

<template>
    <LegalDocument :meta="LEGAL_DOCUMENTS.privacy">
        <template #mk>
            <p>
                Оваа Политика за приватност објаснува како се обработуваат вашите лични податоци кога ја користите
                оваа алатка за пребарување и цитирање на македонски правни прописи („Услугата"). Услугата пронаоѓа и
                цитира законски одредби и не дава правен совет.
            </p>

            <h2>1. Контролор на личните податоци</h2>
            <p>
                Контролор на збирката лични податоци е
                <span class="tbc">[СЕ ДОПОЛНУВА: назив на правното лице/операторот, седиште и матичен број]</span>.
                Офицер за заштита на личните податоци:
                <span class="tbc">[СЕ ДОПОЛНУВА: име и контакт, доколку е определен]</span>.
            </p>

            <h2>2. Кои лични податоци ги собираме</h2>
            <ul>
                <li><strong>Податоци за сметката:</strong> име, адреса на е-пошта и лозинка (која се чува во хеширана форма, не како обичен текст).</li>
                <li><strong>Податоци од профилот:</strong> краток опис (биографија) доколку го внесете и време на последна активност.</li>
                <li><strong>Содржина од разговорите:</strong> прашањата што ги внесувате, автоматски генерираните резимеа и историјата на вашите разговори.</li>
                <li><strong>Технички податоци:</strong> IP-адреса, вид на прелистувач (user-agent) и податоци за сесијата, кои се чуваат заради најавување и безбедност.</li>
                <li><strong>Дневници за настани (logs):</strong> технички записи за работата на системот (на пр. времетраење на обработката, шифри на грешки). Овие записи <strong>не</strong> ја содржат содржината на вашите прашања.</li>
            </ul>

            <h2>3. Цели и правен основ на обработката</h2>
            <ul>
                <li><strong>Обезбедување на Услугата</strong> (создавање сметка, најавување, водење на разговорите) — правен основ: извршување на договор.</li>
                <li><strong>Безбедност и спречување злоупотреба</strong> (ограничување на бројот на барања, заштита на сметките) — правен основ: легитимен интерес.</li>
            </ul>
            <p>
                Правниот основ произлегува од Законот за заштита на личните податоци (ЗЗЛП) и Општата регулатива за
                заштита на податоците (ГДПР).
                <span class="tbc">[СЕ ДОПОЛНУВА: точни членови од ЗЗЛП/ГДПР за секој правен основ]</span>.
            </p>

            <h2>4. Пренос до трети страни (обработувачи)</h2>
            <p>
                <strong>Google (Gemini API).</strong> За да го изготви резимето, Услугата го испраќа вашето прашање
                заедно со пронајдените законски одредби до Google, преку Gemini API. Обработката се одвива на серверите
                на Google, лоцирани во САД.
            </p>
            <p>
                Според тековната конфигурација (бесплатното ниво на Gemini API), Google може да ја користи испратената
                содржина — вклучувајќи ги и вашите прашања — за да ги обезбедува и подобрува своите услуги, а негови
                проверувачи може да ја прегледаат таквата содржина. <strong>Затоа, не внесувајте лични, доверливи или
                чувствителни податоци во прашањата.</strong>
                <span class="tbc">[СЕ ДОПОЛНУВА: потврди го нивото на Gemini API што се користи и наведи ги важечките услови на Google за користење на податоците]</span>.
            </p>
            <p>
                <strong>Хостинг/инфраструктура:</strong>
                <span class="tbc">[СЕ ДОПОЛНУВА: давател на хостинг и локација на серверите]</span>.
                Не продаваме лични податоци и не ги споделуваме за рекламни цели.
            </p>

            <h2>5. Меѓународен пренос на податоци (во САД)</h2>
            <p>
                Со испраќањето на прашањата до Google, вашите податоци се пренесуваат во трета земја (САД), која може да
                нема исто ниво на заштита на личните податоци како Република Северна Македонија или Европската Унија.
                <span class="tbc">[СЕ ДОПОЛНУВА: правен механизам за преносот — одлука за адекватност, стандардни договорни клаузули или друга основа согласно ЗЗЛП]</span>.
            </p>

            <h2>6. Рокови на чување</h2>
            <ul>
                <li>Податоците за сметката и содржината на разговорите се чуваат додека постои вашата сметка. Во моментот, содржината на разговорите се чува неограничено додека вие не ја избришете. <span class="tbc">[СЕ ДОПОЛНУВА: конкретна политика за рокови на чување и бришење]</span>.</li>
                <li>Сесиските податоци се чуваат за времетраењето на сесијата (стандардно 120 минути неактивност).</li>
                <li>Дневниците за настани се чуваат 14 дена, по што автоматски се бришат.</li>
                <li>Роковите на чување кај Google се уредени со условите на Google. <span class="tbc">[СЕ ДОПОЛНУВА]</span>.</li>
            </ul>

            <h2>7. Вашите права</h2>
            <p>Согласно ЗЗЛП и ГДПР, имате право на:</p>
            <ul>
                <li>пристап до вашите лични податоци;</li>
                <li>исправка на неточни или нецелосни податоци;</li>
                <li>бришење на податоците („право да се биде заборавен");</li>
                <li>преносливост на податоците;</li>
                <li>приговор на обработката;</li>
                <li>ограничување на обработката.</li>
            </ul>
            <p>
                Дел од овие права можете да ги остварите директно во апликацијата: да ги преземете вашите податоци и да
                ја избришете сметката преку страницата „Профил". За останатите барања, контактирајте нè на
                <span class="tbc">[СЕ ДОПОЛНУВА: е-пошта за барања поврзани со лични податоци]</span>.
            </p>

            <h2>8. Колачиња (cookies)</h2>
            <p>
                Услугата користи само неопходни колачиња: сесиско колаче за најавување и колаче за заштита од CSRF
                (XSRF-TOKEN). Не користиме аналитички или рекламни колачиња.
            </p>

            <h2>9. Безбедност</h2>
            <p>
                Лозинките се чуваат во хеширана форма, а пристапот до Услугата е ограничен (регистрацијата е само со
                покана).
                <span class="tbc">[СЕ ДОПОЛНУВА: дополнителни технички и организациски мерки, на пр. HTTPS/криптирање]</span>.
            </p>

            <h2>10. Малолетни лица</h2>
            <p>
                Услугата не е наменета за лица под
                <span class="tbc">[СЕ ДОПОЛНУВА: возрасна граница]</span> години.
            </p>

            <h2>11. Надзорен орган</h2>
            <p>
                Имате право да поднесете претставка до Агенцијата за заштита на личните податоци (АЗЛП) на Република
                Северна Македонија.
                <span class="tbc">[СЕ ДОПОЛНУВА: контакт-детали на АЗЛП]</span>.
            </p>

            <h2>12. Измени на оваа Политика</h2>
            <p>
                Оваа Политика може да се измени. Секоја верзија е означена со број и датум на важност; продолженото
                користење на Услугата по објавата на изменета верзија значи запознавање со неа.
            </p>

            <h2>13. Контакт</h2>
            <p>
                <span class="tbc">[СЕ ДОПОЛНУВА: назив, адреса и е-пошта за контакт]</span>.
            </p>
        </template>

        <template #en>
            <p>
                This Privacy Policy explains how your personal data is processed when you use this tool for searching
                and citing Macedonian legislation (the “Service”). The Service finds and cites statutory provisions and
                does not provide legal advice.
            </p>

            <h2>1. Data controller</h2>
            <p>
                The controller of the personal data collection is
                <span class="tbc">[TO BE COMPLETED: name of the legal entity/operator, seat and registration number]</span>.
                Data protection officer:
                <span class="tbc">[TO BE COMPLETED: name and contact, if appointed]</span>.
            </p>

            <h2>2. What personal data we collect</h2>
            <ul>
                <li><strong>Account data:</strong> name, email address and password (stored hashed, never as plain text).</li>
                <li><strong>Profile data:</strong> a short bio if you provide one, and your last-active time.</li>
                <li><strong>Conversation content:</strong> the questions you enter, the automatically generated summaries, and your conversation history.</li>
                <li><strong>Technical data:</strong> IP address, browser type (user-agent) and session data, retained for login and security.</li>
                <li><strong>Event logs:</strong> technical records of system operation (e.g. processing durations, error codes). These logs do <strong>not</strong> contain the content of your questions.</li>
            </ul>

            <h2>3. Purposes and legal basis</h2>
            <ul>
                <li><strong>Providing the Service</strong> (account creation, login, running conversations) — legal basis: performance of a contract.</li>
                <li><strong>Security and abuse prevention</strong> (rate limiting, protecting accounts) — legal basis: legitimate interest.</li>
            </ul>
            <p>
                The legal basis derives from the Law on Personal Data Protection (ЗЗЛП) and the General Data Protection
                Regulation (GDPR).
                <span class="tbc">[TO BE COMPLETED: exact ЗЗЛП/GDPR articles for each basis]</span>.
            </p>

            <h2>4. Transfers to third parties (processors)</h2>
            <p>
                <strong>Google (Gemini API).</strong> To produce the summary, the Service sends your question together
                with the retrieved statutory provisions to Google via the Gemini API. Processing takes place on Google’s
                servers, located in the United States.
            </p>
            <p>
                Under the current configuration (the free tier of the Gemini API), Google may use the transmitted
                content — including your questions — to provide and improve its services, and Google reviewers may read
                such content. <strong>For this reason, do not enter personal, confidential or sensitive data in your
                questions.</strong>
                <span class="tbc">[TO BE COMPLETED: confirm the Gemini API tier in use and cite Google’s current data-use terms]</span>.
            </p>
            <p>
                <strong>Hosting/infrastructure:</strong>
                <span class="tbc">[TO BE COMPLETED: hosting provider and server location]</span>.
                We do not sell personal data and do not share it for advertising.
            </p>

            <h2>5. International data transfer (to the USA)</h2>
            <p>
                By sending your questions to Google, your data is transferred to a third country (the USA), which may
                not offer the same level of data protection as North Macedonia or the European Union.
                <span class="tbc">[TO BE COMPLETED: legal transfer mechanism — adequacy decision, standard contractual clauses, or another basis under ЗЗЛП]</span>.
            </p>

            <h2>6. Retention periods</h2>
            <ul>
                <li>Account data and conversation content are kept while your account exists. Currently, conversation content is retained indefinitely until you delete it. <span class="tbc">[TO BE COMPLETED: concrete retention and deletion policy]</span>.</li>
                <li>Session data is kept for the duration of the session (120 minutes of inactivity by default).</li>
                <li>Event logs are kept for 14 days and then automatically deleted.</li>
                <li>Retention at Google is governed by Google’s terms. <span class="tbc">[TO BE COMPLETED]</span>.</li>
            </ul>

            <h2>7. Your rights</h2>
            <p>Under ЗЗЛП and the GDPR, you have the right to:</p>
            <ul>
                <li>access your personal data;</li>
                <li>rectify inaccurate or incomplete data;</li>
                <li>erase data (the “right to be forgotten”);</li>
                <li>data portability;</li>
                <li>object to processing;</li>
                <li>restrict processing.</li>
            </ul>
            <p>
                Some of these rights you can exercise directly in the app: you can export your data and delete your
                account from the “Profile” page. For other requests, contact us at
                <span class="tbc">[TO BE COMPLETED: email for data protection requests]</span>.
            </p>

            <h2>8. Cookies</h2>
            <p>
                The Service uses only essential cookies: a session cookie for login and a CSRF-protection cookie
                (XSRF-TOKEN). We do not use analytics or advertising cookies.
            </p>

            <h2>9. Security</h2>
            <p>
                Passwords are stored hashed and access to the Service is restricted (registration is by invitation
                only).
                <span class="tbc">[TO BE COMPLETED: additional technical and organisational measures, e.g. HTTPS/encryption]</span>.
            </p>

            <h2>10. Minors</h2>
            <p>
                The Service is not intended for persons under
                <span class="tbc">[TO BE COMPLETED: age threshold]</span> years of age.
            </p>

            <h2>11. Supervisory authority</h2>
            <p>
                You have the right to lodge a complaint with the Personal Data Protection Agency (АЗЛП) of the Republic
                of North Macedonia.
                <span class="tbc">[TO BE COMPLETED: АЗЛП contact details]</span>.
            </p>

            <h2>12. Changes to this Policy</h2>
            <p>
                This Policy may be amended. Each version is marked with a number and effective date; continued use of the
                Service after a revised version is published constitutes acknowledgement of it.
            </p>

            <h2>13. Contact</h2>
            <p>
                <span class="tbc">[TO BE COMPLETED: name, address and contact email]</span>.
            </p>
        </template>
    </LegalDocument>
</template>
