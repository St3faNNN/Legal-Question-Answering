<script setup lang="ts">
import LegalDocument from '@/components/LegalDocument.vue'
import { LEGAL_DOCUMENTS } from '@/legal/documents'
</script>

<template>
    <LegalDocument :meta="LEGAL_DOCUMENTS.disclaimer">
        <template #mk>
            <h2>Одрекување од одговорност</h2>
            <p>
                Оваа алатка пронаоѓа и цитира одредби од македонските правни прописи и изготвува автоматски генерирано
                резиме. Пред да се потпрете на неа, ве молиме имајте го предвид следново:
            </p>
            <ul>
                <li>
                    <strong>Не е правен совет.</strong> Содржината е само информативна и не претставува правен совет,
                    ниту создава однос адвокат–клиент. За вашата конкретна ситуација, консултирајте квалификуван адвокат.
                </li>
                <li>
                    <strong>Автоматски генерирано.</strong> Резимето е изработено со вештачка интелигенција и може да
                    биде неточно, нецелосно или застарено. Може да испушти суштински одредби или исклучоци.
                </li>
                <li>
                    <strong>Меродавен е официјалниот текст.</strong> За секое прашање, меродавен е официјално објавениот
                    текст на прописите во „Службен весник на Република Северна Македонија". Во случај на разлика, важи
                    официјалниот текст, а не она што го прикажува алатката.
                </li>
                <li>
                    <strong>Без одговорност.</strong> Операторот не одговара за одлуки или дејствија преземени врз основа
                    на содржината добиена преку оваа алатка.
                    <span class="tbc">[СЕ ДОПОЛНУВА: усогласување со важечкото право]</span>.
                </li>
            </ul>
            <p>
                Ова Одрекување треба да се чита заедно со <RouterLink to="/terms">Условите за користење</RouterLink> и
                <RouterLink to="/privacy">Политиката за приватност</RouterLink>.
            </p>
        </template>

        <template #en>
            <h2>Disclaimer</h2>
            <p>
                This tool finds and cites provisions of Macedonian legislation and produces an automatically generated
                summary. Before relying on it, please note the following:
            </p>
            <ul>
                <li>
                    <strong>Not legal advice.</strong> The content is informational only and does not constitute legal
                    advice, nor does it create a lawyer–client relationship. For your specific situation, consult a
                    qualified lawyer.
                </li>
                <li>
                    <strong>Automatically generated.</strong> The summary is produced by artificial intelligence and may
                    be inaccurate, incomplete or out of date. It may omit material provisions or exceptions.
                </li>
                <li>
                    <strong>The official text prevails.</strong> For any matter, the officially published text of the
                    legislation in the “Official Gazette of the Republic of North Macedonia” is authoritative. In the
                    event of a discrepancy, the official text governs, not what the tool displays.
                </li>
                <li>
                    <strong>No liability.</strong> The operator is not liable for decisions or actions taken on the basis
                    of content obtained through this tool.
                    <span class="tbc">[TO BE COMPLETED: alignment with applicable law]</span>.
                </li>
            </ul>
            <p>
                This Disclaimer should be read together with the <RouterLink to="/terms">Terms of Use</RouterLink> and
                the <RouterLink to="/privacy">Privacy Policy</RouterLink>.
            </p>
        </template>
    </LegalDocument>
</template>
