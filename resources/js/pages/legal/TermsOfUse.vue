<script setup lang="ts">
import LegalDocument from '@/components/LegalDocument.vue'
import { LEGAL_DOCUMENTS } from '@/legal/documents'
</script>

<template>
    <LegalDocument :meta="LEGAL_DOCUMENTS.terms">
        <template #mk>
            <p>
                Со користењето на оваа алатка за пребарување и цитирање на македонски правни прописи („Услугата"), вие
                ги прифаќате овие Услови за користење. Ако не се согласувате со нив, не ја користете Услугата.
            </p>

            <h2>1. Опис на Услугата</h2>
            <p>
                Услугата пронаоѓа и прикажува („цитира") релевантни законски одредби во одговор на вашето прашање и
                дополнително изготвува автоматски генерирано резиме. Услугата обезбедува пристап до правни информации,
                а не правен совет.
            </p>

            <h2>2. Не претставува правен совет</h2>
            <p>
                Содржината достапна преку Услугата служи само за информативни цели и <strong>не претставува правен
                совет</strong>. Користењето на Услугата <strong>не создава однос адвокат–клиент</strong> ниту каков било
                професионален однос. За совет за вашата конкретна ситуација, консултирајте квалификуван адвокат.
                И Google, чиј API ги генерира резимеата, во своите услови наведува дека неговите услуги не треба да
                се користат како извор на правен совет.
            </p>

            <h2>3. Без гаранција за точност</h2>
            <p>
                Услугата се обезбедува „таква каква што е", без каква било гаранција за точност, целосност или ажурност.
                Автоматски генерираните резимеа може да содржат грешки или да испуштат суштински одредби.
                <strong>Меродавен е официјално објавениот текст на прописите во „Службен весник на Република Северна
                Македонија"</strong>; во случај на каква било разлика, важи официјалниот текст, а не она што го
                прикажува Услугата.
            </p>

            <h2>4. Обврски на корисникот</h2>
            <ul>
                <li>Да не внесувате лични, доверливи или чувствителни податоци во прашањата (тие се испраќаат до надворешен обработувач — видете ја Политиката за приватност).</li>
                <li>Да не ја користите Услугата за незаконити цели или на начин што ја оптоварува или нарушува нејзината работа.</li>
                <li>Да ги чувате пристапните податоци за вашата сметка и да не ги споделувате.</li>
            </ul>

            <h2>5. Регистрација само со покана</h2>
            <p>
                Создавањето сметка е можно само со важечки код за покана. Задржуваме право да одбиеме или укинеме
                пристап.
            </p>

            <h2>6. Интелектуална сопственост</h2>
            <p>
                Текстовите на законите и другите прописи се официјални акти. Софтверот и изгледот на Услугата се
                заштитени согласно закон.
                <span class="tbc">[СЕ ДОПОЛНУВА: одредби за интелектуална сопственост и лиценца, доколку е потребно]</span>.
            </p>

            <h2>7. Ограничување на одговорност</h2>
            <p>
                Во најголемата мера дозволена со закон, операторот на Услугата не одговара за какви било штети што
                произлегуваат од користењето или потпирањето на Услугата, вклучувајќи одлуки донесени врз основа на
                прикажаните информации или резимеа.
                <span class="tbc">[СЕ ДОПОЛНУВА: усогласување со важечкото право за ограничување на одговорност]</span>.
            </p>

            <h2>8. Прекин</h2>
            <p>
                Можете да престанете да ја користите Услугата во секое време и да ја избришете сметката. Задржуваме
                право да го прекинеме или ограничиме пристапот при прекршување на овие Услови.
            </p>

            <h2>9. Измени на Условите</h2>
            <p>
                Овие Услови може да се изменат. Секоја верзија е означена со број и датум на важност. Продолженото
                користење по објавата на изменета верзија значи прифаќање на новата верзија.
            </p>

            <h2>10. Меродавно право и надлежност</h2>
            <p>
                Овие Услови се уредуваат според правото на Република Северна Македонија. Надлежни се судовите на
                Република Северна Македонија.
                <span class="tbc">[СЕ ДОПОЛНУВА: потврди го стварно надлежниот суд — за правен преглед]</span>.
            </p>

            <h2>11. Контакт</h2>
            <p>
                СД.
                <span class="tbc">[СЕ ДОПОЛНУВА: контакт е-пошта]</span>.
            </p>
        </template>

        <template #en>
            <p>
                By using this tool for searching and citing Macedonian legislation (the “Service”), you accept these
                Terms of Use. If you do not agree with them, do not use the Service.
            </p>

            <h2>1. Description of the Service</h2>
            <p>
                The Service finds and displays (“cites”) relevant statutory provisions in response to your question and
                additionally produces an automatically generated summary. The Service provides access to legal
                information, not legal advice.
            </p>

            <h2>2. Not legal advice</h2>
            <p>
                Content available through the Service is for informational purposes only and <strong>does not constitute
                legal advice</strong>. Using the Service <strong>does not create a lawyer–client relationship</strong> or
                any professional relationship. For advice on your specific situation, consult a qualified lawyer.
                Google, whose API generates the summaries, likewise states in its terms that its services should not
                be relied upon for legal advice.
            </p>

            <h2>3. No warranty of accuracy</h2>
            <p>
                The Service is provided “as is”, without any warranty of accuracy, completeness or currency. The
                automatically generated summaries may contain errors or omit material provisions.
                <strong>The officially published text of the legislation in the “Official Gazette of the Republic of
                North Macedonia” prevails</strong>; in the event of any discrepancy, the official text governs, not what
                the Service displays.
            </p>

            <h2>4. User obligations</h2>
            <ul>
                <li>Do not enter personal, confidential or sensitive data in your questions (they are sent to an external processor — see the Privacy Policy).</li>
                <li>Do not use the Service for unlawful purposes or in a way that overloads or disrupts its operation.</li>
                <li>Keep your account credentials secure and do not share them.</li>
            </ul>

            <h2>5. Invitation-only registration</h2>
            <p>
                Creating an account is possible only with a valid invitation code. We reserve the right to refuse or
                revoke access.
            </p>

            <h2>6. Intellectual property</h2>
            <p>
                The texts of laws and other regulations are official acts. The software and design of the Service are
                protected by law.
                <span class="tbc">[TO BE COMPLETED: intellectual-property and licence provisions, if needed]</span>.
            </p>

            <h2>7. Limitation of liability</h2>
            <p>
                To the maximum extent permitted by law, the operator of the Service is not liable for any damages
                arising from the use of, or reliance on, the Service, including decisions made on the basis of the
                displayed information or summaries.
                <span class="tbc">[TO BE COMPLETED: alignment with applicable law on limitation of liability]</span>.
            </p>

            <h2>8. Termination</h2>
            <p>
                You may stop using the Service at any time and delete your account. We reserve the right to suspend or
                restrict access in case of a breach of these Terms.
            </p>

            <h2>9. Changes to the Terms</h2>
            <p>
                These Terms may be amended. Each version is marked with a number and effective date. Continued use after
                a revised version is published constitutes acceptance of the new version.
            </p>

            <h2>10. Governing law and jurisdiction</h2>
            <p>
                These Terms are governed by the law of the Republic of North Macedonia. The competent courts of the
                Republic of North Macedonia shall have jurisdiction.
                <span class="tbc">[TO BE COMPLETED: confirm competent court — for legal review]</span>.
            </p>

            <h2>11. Contact</h2>
            <p>
                SD.
                <span class="tbc">[TO BE COMPLETED: contact email]</span>.
            </p>
        </template>
    </LegalDocument>
</template>
