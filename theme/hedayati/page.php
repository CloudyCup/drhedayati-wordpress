<?php
/** Editable public pages, with shared contact/teacher sections. */
get_header();
?>
<main id="site-main" class="section hd-public-page" tabindex="-1"><div class="container">
<?php while ( have_posts() ) : the_post(); $hd_slug = get_post_field( 'post_name', get_the_ID() ); ?>
 <header class="section-heading"><span>مجتمع آموزشی دکتر هدایتی</span><h1><?php the_title(); ?></h1></header>
 <div class="hd-page-copy"><?php the_content(); ?></div>
 <?php if ( 'about' === $hd_slug ) : ?>
 <div class="hd-public-grid"><section class="hd-public-card"><h2>دوره‌های آموزشی</h2><p>دوره‌های کامپیوتر و فناوری اطلاعات را بررسی کنید و مسیر آموزشی متناسب با علاقه و هدف خود را انتخاب کنید.</p><a class="solid-btn" href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>">مشاهده دوره‌ها</a></section><section class="hd-public-card"><h2>انتخاب مسیر یادگیری</h2><p>برای پرسش درباره پیش‌نیازها و انتخاب دوره با مجتمع تماس بگیرید.</p><a class="outline-btn" href="<?php echo esc_url( home_url( '/consult/' ) ); ?>">مشاوره انتخاب دوره</a></section></div>
 <?php endif; ?>
 <?php if ( in_array( $hd_slug, [ 'contact', 'consult' ], true ) && class_exists( 'Hedayati_Settings' ) ) : ?>
 <?php if ( 'consult' === $hd_slug ) : ?><p class="hd-page-lead">برای انتخاب دوره، بررسی پیش‌نیازها و اطلاع از زمان ثبت‌نام با مجتمع تماس بگیرید.</p><?php endif; ?>
 <div class="hd-public-grid">
 <?php foreach ( [ 'phone_consult' => 'مشاوره و ثبت‌نام', 'phone_tabriz' => 'مجتمع تبریز', 'phone_tehran' => 'مجتمع تهران' ] as $hd_key => $hd_label ) : $hd_phone = Hedayati_Settings::get( $hd_key ); if ( '' === $hd_phone ) { continue; } ?>
 <section class="hd-public-card"><h2><?php echo esc_html( $hd_label ); ?></h2><a class="hd-contact-phone" href="tel:<?php echo esc_attr( Hedayati_Settings::tel_uri( $hd_key ) ); ?>" dir="ltr"><?php echo esc_html( $hd_phone ); ?></a></section>
 <?php endforeach; ?>
 <?php $hd_address = Hedayati_Settings::get( 'address_tabriz' ); if ( '' !== $hd_address ) : ?><section class="hd-public-card"><h2>نشانی تبریز</h2><address><?php echo nl2br( esc_html( $hd_address ) ); ?></address></section><?php endif; ?>
 </div>
 <?php endif; ?>
 <?php if ( 'teachers' === $hd_slug && class_exists( 'Hedayati_Public_Content' ) ) : $hd_teachers = Hedayati_Public_Content::teachers(); ?>
 <div class="hd-public-grid"><?php foreach ( $hd_teachers as $hd_teacher ) : ?><article class="hd-public-card" id="teacher-<?php echo esc_attr( (string) $hd_teacher['id'] ); ?>"><?php echo $hd_teacher['image']; ?><h2><?php echo esc_html( $hd_teacher['name'] ); ?></h2><p><?php echo esc_html( $hd_teacher['headline'] ); ?></p><div><?php echo wpautop( $hd_teacher['bio'] ); ?></div></article><?php endforeach; ?></div>
 <?php if ( ! $hd_teachers ) : ?><p>معرفی مدرسان به‌زودی در این صفحه منتشر می‌شود.</p><?php endif; ?>
 <?php endif; ?>
<?php endwhile; ?>
</div></main>
<?php get_footer(); ?>
