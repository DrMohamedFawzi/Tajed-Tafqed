<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - كيف تعمل المنصة والخصوصية
 * Plain-language guide: steps, what is public, safety tips, contact.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/layout.php';

setSecurityHeaders();

pageStart('كيف تعمل المنصة والخصوصية', 'about');
?>

<div class="container prose-page">
    <div class="page-heading">
        <h1>كيف تعمل المنصة؟</h1>
        <p>منصة مجتمعية مجانية تساعد أهل غزة على إعادة الوثائق والأغراض المفقودة لأصحابها.</p>
    </div>

    <ol class="steps">
        <li>
            <span class="step-num">1</span>
            <div>
                <h2>فقدتَ شيئاً؟ سجّل بلاغاً</h2>
                <p>اكتب ما فقدته والمنطقة ورقم جوالك. إن كان معك رقم الوثيقة (هوية، جواز، IMEI) فاكتبه: من يجدها ويبحث بالرقم يصل إلى بلاغك فوراً.</p>
            </div>
        </li>
        <li>
            <span class="step-num">2</span>
            <div>
                <h2>وجدتَ شيئاً؟ سجّل الأمانة</h2>
                <p>اكتب ما وجدته وأين. إن كان مالاً أو شيئاً ثميناً، ضع سؤال تحقق لا يعرف جوابه إلا صاحبه، ولا تنشر كل مواصفاته.</p>
            </div>
        </li>
        <li>
            <span class="step-num">3</span>
            <div>
                <h2>ابحث وتواصل</h2>
                <p>ابحث برقم الوثيقة أو الاسم. عندما تجد البلاغ، اضغط «إظهار رقم الجوال» أو أجب عن سؤال التحقق، ثم اتصل واتفقا على الاستلام.</p>
            </div>
        </li>
        <li>
            <span class="step-num">4</span>
            <div>
                <h2>أغلق البلاغ عند الاستلام</h2>
                <p>عند تسجيل أي بلاغ يظهر لك <strong>رمز إدارة</strong> مرة واحدة. احفظه. به وحدك تستطيع إغلاق البلاغ عندما تعود الأمانة، فيُحذف رقمك من المنصة.</p>
            </div>
        </li>
    </ol>

    <section class="info-section">
        <h2><?= icon('lock') ?> ما الذي يراه الناس، وما الذي لا يرونه؟</h2>
        <div class="two-col">
            <div class="info-box">
                <h3>يظهر للجميع</h3>
                <ul>
                    <li>وصف الغرض ونوعه</li>
                    <li>المنطقة والتاريخ</li>
                    <li>مكان الاستلام إن وُجد</li>
                    <li>سؤال التحقق (دون جوابه)</li>
                </ul>
            </div>
            <div class="info-box">
                <h3>لا يظهر لأحد</h3>
                <ul>
                    <li>رقم الوثيقة: نحفظه مشفّراً للمطابقة فقط</li>
                    <li>جواب سؤال التحقق</li>
                    <li>رمز الإدارة</li>
                </ul>
            </div>
        </div>
        <p class="info-note">
            <strong>رقم الجوال</strong> لا يظهر في قائمة البلاغات. يظهر فقط لمن يطلبه بالضغط على «إظهار رقم الجوال» (بعدد محدود لكل زائر)،
            أو لمن يجيب سؤال التحقق إن وُضع. ويُحذف نهائياً من المنصة عند إغلاق البلاغ.
        </p>
    </section>

    <section class="info-section">
        <h2><?= icon('tag') ?> ما هو رمز الأمانة؟</h2>
        <p>رمز قصير مثل <bdi dir="ltr">GZ-7A3F</bdi> تنشئه لأغراضك قبل أن تضيع، وتكتبه عليها. من يجد الغرض يُدخل الرمز في الصفحة الرئيسية، فيظهر له اسمك ورقمك ليتصل بك مباشرة. لذلك اكتب الرمز على الغرض نفسه فقط.</p>
    </section>

    <section class="info-section">
        <h2><?= icon('shield') ?> نصائح للأمان</h2>
        <ul class="tips">
            <li>اتفقوا على الاستلام في مكان عام، مثل صيدلية أو مسجد أو نقطة أمانات.</li>
            <li>لا تدفع مالاً لأحد مقابل إعادة وثيقتك. الأمانة تُرد لوجه الله.</li>
            <li>إن وجدت شيئاً ثميناً، اطلب من صاحبه وصفاً دقيقاً قبل أن تسلّمه.</li>
            <li>لا تعطِ رمز الإدارة لأحد، ولا تنشره مع البلاغ.</li>
        </ul>
    </section>

    <section class="info-section">
        <h2><?= icon('alert') ?> بلاغ خاطئ أو مسيء؟ أضعت رمز الإدارة؟</h2>
        <p><?= adminContactHtml() ?>، وأرسل رقم البلاغ الظاهر عليه.</p>
    </section>

    <p class="text-center" style="margin: 2rem 0 3rem;">
        <a href="report.php" class="btn btn-primary"><?= icon('plus') ?> سجّل بلاغاً</a>
        <a href="index.php#searchSection" class="btn btn-outline-primary"><?= icon('search') ?> ابحث في البلاغات</a>
    </p>
</div>

<?php
pageEnd();
