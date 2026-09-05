<?php
/** Shared staff shell; authorization is also checked by the plugin renderer. */
if ( ! class_exists( 'Hedayati_Staff_Portal' ) ) { status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>پنل موقتاً در دسترس نیست.</p></main>'; get_footer(); return; }
get_header();
?>
<main id="site-main" class="hd-portal-main section" tabindex="-1">
 <div class="container hd-portal-shell">
  <nav class="hd-portal-sidebar" aria-label="منوی پنل آموزش"><ul class="hd-portal-nav">
   <li><a class="hd-portal-nav-link" href="<?php echo esc_url( Hedayati_Staff_Portal::url() ); ?>">پنل آموزش</a></li>
   <?php if ( current_user_can( 'hedayati_lookup_students' ) ) : ?><li><a class="hd-portal-nav-link" href="<?php echo esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'students' ] ) ); ?>">پذیرش و دانشجویان</a></li><?php endif; ?>
   <li><a class="hd-portal-nav-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">وب‌سایت مجتمع</a></li>
   <li><a class="hd-portal-nav-link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج</a></li>
  </ul></nav>
  <div class="hd-portal-content"><?php Hedayati_Staff_Portal::render(); ?></div>
 </div>
</main>
<?php get_footer(); ?>
