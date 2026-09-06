<?php
/** Shared staff shell; authorization is also checked by the plugin renderer. */
if ( ! class_exists( 'Hedayati_Staff_Portal' ) ) { status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>پنل موقتاً در دسترس نیست.</p></main>'; get_footer(); return; }
$hd_is_manager = Hedayati_Staff_Portal::is_manager_workspace();
$hd_view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
get_header();
?>
<main id="site-main" class="hd-portal-main section<?php echo $hd_is_manager ? ' hd-manager-main' : ''; ?>" tabindex="-1">
 <div class="container hd-portal-shell<?php echo $hd_is_manager ? ' hd-manager-shell' : ''; ?>">
  <nav class="hd-portal-sidebar<?php echo $hd_is_manager ? ' hd-manager-sidebar' : ''; ?>" aria-label="منوی پنل آموزش">
   <?php if ( $hd_is_manager ) : ?>
    <div class="hd-manager-brand"><span aria-hidden="true">هـ</span><div><strong>پنل مدیریت</strong><small>مجتمع دکتر هدایتی</small></div></div>
   <?php endif; ?>
   <ul class="hd-portal-nav">
    <li><a class="hd-portal-nav-link<?php echo '' === $hd_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( Hedayati_Staff_Portal::url() ); ?>"><?php echo $hd_is_manager ? 'داشبورد جامع' : 'پنل آموزش'; ?></a></li>
    <?php if ( current_user_can( 'hedayati_lookup_students' ) ) : ?><li><a class="hd-portal-nav-link<?php echo 'students' === $hd_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'students' ] ) ); ?>">پذیرش و دانشجویان</a></li><?php endif; ?>
    <?php if ( $hd_is_manager && current_user_can( 'hedayati_manage_courses' ) ) : ?>
     <li><a class="hd-portal-nav-link<?php echo 'courses' === $hd_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'courses' ] ) ); ?>">دوره‌ها و محتوای آموزشی</a></li>
     <li><a class="hd-portal-nav-link<?php echo 'featured' === $hd_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'featured' ] ) ); ?>">دوره‌های ویژهٔ صفحهٔ نخست</a></li>
    <?php endif; ?>
    <?php if ( $hd_is_manager && current_user_can( 'hedayati_manage_course_runs' ) ) : ?><li><a class="hd-portal-nav-link" href="<?php echo esc_url( admin_url( 'admin.php?page=hedayati-academic' ) ); ?>">عملیات آموزشی</a></li><?php endif; ?>
    <?php if ( $hd_is_manager && current_user_can( 'hedayati_manage_teachers' ) ) : ?><li><a class="hd-portal-nav-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=teacher' ) ); ?>">اساتید</a></li><?php endif; ?>
    <?php if ( $hd_is_manager && current_user_can( 'hedayati_verify_students' ) ) : ?><li><a class="hd-portal-nav-link" href="<?php echo esc_url( admin_url( 'admin.php?page=hedayati-students' ) ); ?>">احراز هویت</a></li><?php endif; ?>
    <?php if ( $hd_is_manager && current_user_can( 'hedayati_manage_settings' ) ) : ?><li><a class="hd-portal-nav-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=hedayati-settings' ) ); ?>">تنظیمات مجتمع</a></li><?php endif; ?>
    <li class="hd-portal-nav-site"><a class="hd-portal-nav-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">مشاهدهٔ وب‌سایت</a></li>
    <li><a class="hd-portal-nav-link hd-portal-nav-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج از پنل</a></li>
   </ul>
  </nav>
  <div class="hd-portal-content"><?php Hedayati_Staff_Portal::render(); ?></div>
 </div>
</main>
<?php get_footer(); ?>
