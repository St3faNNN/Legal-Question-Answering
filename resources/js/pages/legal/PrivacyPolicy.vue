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
                Контролор на збирката лични податоци е СД, физичко лице како оператор, Република Северна
                Македонија. Не постои регистрирано правно лице — Услугата ја обезбедува физичко лице како
                некомерцијален проект.
                <span class="tbc">[СЕ ДОПОЛНУВА: целосна идентификација согласно ЗЗЛП — за правен преглед]</span>.
                Офицер за заштита на личните податоци: не е определен; Услугата не ги исполнува условите за
                задолжително определување.
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
                Услугата го користи Google Gemini API на бесплатното ниво („Unpaid Services“ — неплатени услуги).
                Според Дополнителните услови за користење на Gemini API (во важност од 23 март 2026 година,
                <code>https://ai.google.dev/gemini-api/terms</code>), Google ја користи содржината испратена до
                неплатените услуги и генерираните одговори за да ги обезбедува, подобрува и развива своите производи,
                услуги и технологии за машинско учење. Проверувачи–луѓе може да ја читаат, означуваат и обработуваат
                содржината што влегува и излегува од API; Google наведува дека тие податоци се одвојуваат од
                Google-сметката, API-клучот и Cloud-проектот пред прегледот. Условите на Google ги упатуваат
                корисниците да не испраќаат чувствителни, доверливи или лични информации до неплатените услуги.
                <strong>Не внесувајте доверливи, чувствителни или лични податоци што овозможуваат идентификација во
                оваа Услуга.</strong>
            </p>
            <p>
                <strong>Хостинг/инфраструктура:</strong> Amazon Web Services (AWS), регион eu-north-1, Стокхолм,
                Шведска. Податоците за сметката, историјата на разговорите и дневниците на апликацијата се чуваат во
                рамките на Европската Унија.
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
                <li>Податоците за сметката и содржината на разговорите се чуваат сè додека постои вашата сметка. Можете во секое време да избришете поединечни разговори или целата сметка преку страницата „Профил"; со бришењето на сметката трајно се отстрануваат сметката, разговорите, пораките и записите за дадена согласност. Нема автоматско бришење засновано на рок.</li>
                <li>Сесиските податоци се чуваат за времетраењето на сесијата (стандардно 120 минути неактивност).</li>
                <li>Дневниците за настани се чуваат 14 дена, по што автоматски се бришат.</li>
                <li>Содржината испратена до Gemini API се чува согласно сопствените услови на Google и е надвор од наша контрола. Содржината испратена до неплатените услуги може да се чува и да се користи за подобрување на производите и моделите. Бришењето на вашата сметка кај нас не ја брише содржината што е веќе испратена до Google.</li>
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
                <span class="tbc">[СЕ ДОПОЛНУВА: контакт е-пошта]</span>.
            </p>

            <h2>8. Колачиња (cookies)</h2>
            <p>
                Услугата користи само неопходни колачиња: сесиско колаче за најавување и колаче за заштита од CSRF
                (XSRF-TOKEN). Не користиме аналитички или рекламни колачиња.
            </p>

            <h2>9. Безбедност</h2>
            <p>
                Лозинките се чуваат во хеширана форма, а пристапот до Услугата е ограничен (регистрацијата е само со
                покана). Дополнителни технички мерки: лозинките се хешираат со bcrypt; сесиското колаче е HTTP-only
                со атрибут SameSite=Lax; барањата од друг сајт се заштитени со CSRF-токени; а точките за најавување,
                регистрација и разговор имаат ограничување на бројот на барања (rate limiting).
            </p>
            <p>
                <strong>Пристап на операторот до содржината на разговорите.</strong> Разговорите се чуваат во базата на
                податоци на Услугата и <strong>операторот има техничка можност да ги прочита</strong> — вклучувајќи го
                текстот на вашите прашања и на генерираните одговори. Тоа се користи за одржување и решавање проблеми
                (на пример, испитување зошто одреден одговор бил неточен или зошто некое барање не успеало). Не се
                користи за профилирање, за рекламирање, ниту се пренесува на трети страни надвор од обработувачите
                наведени во точка 4. Внатрешниот панел за следење на работата на системот прикажува само технички
                мерки (времетраење, број на пронајдени одредби, статус); текстот на прашањето се прикажува единствено
                по изречно дејство, за еден разговор во даден момент. Ако не сакате едно прашање да остане запишано,
                можете да го избришете разговорот или целата сметка (точка 7).
            </p>

            <h2>10. Малолетни лица</h2>
            <p>
                Услугата не е наменета за лица под 18 години.
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
                СД, Република Северна Македонија.
                <span class="tbc">[СЕ ДОПОЛНУВА: контакт е-пошта]</span>.
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
                The controller of the personal data collection is SD, an individual operator, Republic of North
                Macedonia. There is no registered legal entity — the Service is operated by a natural person as a
                non-commercial project.
                <span class="tbc">[TO BE COMPLETED: full identification as required by ЗЗЛП — for legal review]</span>.
                Data protection officer: none has been appointed; the Service does not meet the criteria requiring
                one.
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
                The Service uses the Google Gemini API on the free tier (“Unpaid Services”). Under the Gemini API
                Additional Terms of Service (effective 23 March 2026,
                <code>https://ai.google.dev/gemini-api/terms</code>), Google uses content submitted to Unpaid
                Services and the generated responses to provide, improve and develop Google products, services and
                machine learning technologies. Human reviewers may read, annotate and process API input and output;
                Google states this data is disconnected from the Google Account, API key and Cloud project before
                review. Google’s terms direct users not to submit sensitive, confidential or personal information to
                Unpaid Services. <strong>Do not enter confidential, sensitive or personally identifying information
                into this Service.</strong>
            </p>
            <p>
                <strong>Hosting/infrastructure:</strong> Amazon Web Services (AWS), eu-north-1 region, Stockholm,
                Sweden. Account data, conversation history and application logs are stored within the European Union.
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
                <li>Account data and conversation content are retained for as long as your account exists. You may delete individual conversations or your entire account at any time from the “Profile” page; account deletion permanently removes your account, conversations, messages and consent records. There is no automatic time-based deletion.</li>
                <li>Session data is kept for the duration of the session (120 minutes of inactivity by default).</li>
                <li>Event logs are kept for 14 days and then automatically deleted.</li>
                <li>Content sent to the Gemini API is retained according to Google’s own terms and is outside our control. Content submitted to Unpaid Services may be retained and used for product and model improvement. Deleting your account here does not delete content already submitted to Google.</li>
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
                <span class="tbc">[TO BE COMPLETED: contact email]</span>.
            </p>

            <h2>8. Cookies</h2>
            <p>
                The Service uses only essential cookies: a session cookie for login and a CSRF-protection cookie
                (XSRF-TOKEN). We do not use analytics or advertising cookies.
            </p>

            <h2>9. Security</h2>
            <p>
                Passwords are stored hashed and access to the Service is restricted (registration is by invitation
                only). Additional technical measures: passwords are hashed with bcrypt; the session cookie is
                HTTP-only with SameSite=Lax; cross-site requests are protected by CSRF tokens; and the login,
                registration and chat endpoints are rate-limited.
            </p>
            <p>
                <strong>Operator access to conversation content.</strong> Conversations are stored in the Service's
                database and <strong>the operator is technically able to read them</strong> — including the text of your
                questions and of the generated answers. This is used for maintenance and troubleshooting (for example,
                investigating why a particular answer was wrong, or why a request failed). It is not used for profiling
                or advertising, and it is not shared with third parties beyond the processors listed in section 4. The
                internal operations panel displays technical measurements only (timings, number of retrieved provisions,
                status); question text is shown only on an explicit action, one conversation at a time. If you do not
                want a question to remain on record, you can delete the conversation or your entire account (section 7).
            </p>

            <h2>10. Minors</h2>
            <p>
                The Service is not intended for persons under 18 years of age.
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
                SD, Republic of North Macedonia.
                <span class="tbc">[TO BE COMPLETED: contact email]</span>.
            </p>
        </template>
    </LegalDocument>
</template>
