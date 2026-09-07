<?php
/**
 * Phase F (D53) — the dedicated front-end authentication page (`/login/`).
 *
 * Auto-selected by the template hierarchy for the `login` page. All request
 * handling (wp_signon, retrieve_password, check_password_reset_key, the
 * redirects) happens earlier in `Hedayati_Login::handle()` on `template_redirect`
 * — this file only renders the branded shell + the right form for `?action=`.
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Login' ) ) {
	status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>صفحهٔ ورود موقتاً در دسترس نیست.</p></main>'; get_footer(); return;
}

get_header();

$hd_action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'login';
$hd_notice  = Hedayati_Login::take_notice();
$hd_redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] )
	? wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), '' )
	: '';
$hd_checkemail = isset( $_GET['checkemail'] ) && 'confirm' === $_GET['checkemail'];
$hd_pwreset    = isset( $_GET['password'] ) && 'reset' === $_GET['password'];
$hd_login_field = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
?>
<main id="site-main" class="hd-auth-main section" role="main" tabindex="-1">
	<div class="hd-auth-shell">

		<aside class="hd-auth-brandside" aria-hidden="true">
			<div class="hd-auth-brandmark">هـ</div>
			<h2>مجتمع آموزشی دکتر هدایتی</h2>
			<p>سامانهٔ یکپارچهٔ آموزش، ثبت‌نام و پیگیری دوره‌ها — تبریز و تهران.</p>
			<div class="hd-auth-pattern"></div>
		</aside>

		<div class="hd-auth-panel">
			<a class="hd-auth-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span aria-hidden="true">هـ</span> مجتمع دکتر هدایتی
			</a>

			<?php if ( ! empty( $hd_notice['text'] ) ) : ?>
				<div class="hd-auth-notice hd-auth-notice-<?php echo esc_attr( $hd_notice['type'] ?? 'error' ); ?>" role="alert">
					<?php echo esc_html( $hd_notice['text'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $hd_checkemail ) : ?>
				<h1 class="hd-auth-title"><?php esc_html_e( 'ایمیل خود را بررسی کنید', 'hedayati' ); ?></h1>
				<p class="hd-auth-lead"><?php esc_html_e( 'اگر حسابی با این مشخصات وجود داشته باشد، پیوند بازیابی رمز عبور برای آن ارسال شد. پیوند تا مدت کوتاهی معتبر است.', 'hedayati' ); ?></p>
				<p><a class="hd-auth-link" href="<?php echo esc_url( Hedayati_Login::url() ); ?>"><?php esc_html_e( 'بازگشت به صفحهٔ ورود', 'hedayati' ); ?></a></p>

			<?php elseif ( 'lostpassword' === $hd_action ) : ?>
				<h1 class="hd-auth-title"><?php esc_html_e( 'بازیابی رمز عبور', 'hedayati' ); ?></h1>
				<p class="hd-auth-lead"><?php esc_html_e( 'نام کاربری یا ایمیل حساب خود را وارد کنید تا پیوند بازیابی برایتان ارسال شود.', 'hedayati' ); ?></p>
				<form class="hd-auth-form" method="post" action="<?php echo esc_url( Hedayati_Login::url( [ 'action' => 'lostpassword' ] ) ); ?>">
					<?php wp_nonce_field( 'hedayati_lostpassword', 'hd_lostpass_nonce' ); ?>
					<label class="hd-auth-field">
						<span><?php esc_html_e( 'نام کاربری یا ایمیل', 'hedayati' ); ?></span>
						<input type="text" name="user_login" autocomplete="username" required autofocus>
					</label>
					<button type="submit" class="hd-auth-btn"><?php esc_html_e( 'ارسال پیوند بازیابی', 'hedayati' ); ?></button>
				</form>
				<p><a class="hd-auth-link" href="<?php echo esc_url( Hedayati_Login::url() ); ?>"><?php esc_html_e( 'بازگشت به صفحهٔ ورود', 'hedayati' ); ?></a></p>

			<?php elseif ( 'resetpass' === $hd_action ) : ?>
				<?php $hd_rp_user = Hedayati_Login::resetpass_user(); ?>
				<?php if ( is_wp_error( $hd_rp_user ) ) : ?>
					<h1 class="hd-auth-title"><?php esc_html_e( 'پیوند نامعتبر', 'hedayati' ); ?></h1>
					<p class="hd-auth-lead"><?php esc_html_e( 'این پیوند بازیابی نامعتبر یا منقضی شده است.', 'hedayati' ); ?></p>
					<p><a class="hd-auth-link" href="<?php echo esc_url( Hedayati_Login::url( [ 'action' => 'lostpassword' ] ) ); ?>"><?php esc_html_e( 'درخواست پیوند جدید', 'hedayati' ); ?></a></p>
				<?php else : ?>
					<h1 class="hd-auth-title"><?php esc_html_e( 'انتخاب رمز عبور جدید', 'hedayati' ); ?></h1>
					<p class="hd-auth-lead"><?php esc_html_e( 'یک رمز عبور جدید و شخصی (حداقل ۱۲ نویسه) انتخاب کنید.', 'hedayati' ); ?></p>
					<form class="hd-auth-form" method="post" action="<?php echo esc_url( Hedayati_Login::url( [ 'action' => 'resetpass' ] ) ); ?>">
						<?php wp_nonce_field( 'hedayati_resetpass', 'hd_resetpass_nonce' ); ?>
						<label class="hd-auth-field">
							<span><?php esc_html_e( 'رمز عبور جدید', 'hedayati' ); ?></span>
							<input type="password" name="pass1" autocomplete="new-password" minlength="12" required dir="ltr" autofocus>
						</label>
						<label class="hd-auth-field">
							<span><?php esc_html_e( 'تکرار رمز عبور جدید', 'hedayati' ); ?></span>
							<input type="password" name="pass2" autocomplete="new-password" minlength="12" required dir="ltr">
						</label>
						<button type="submit" class="hd-auth-btn"><?php esc_html_e( 'ثبت رمز عبور', 'hedayati' ); ?></button>
					</form>
				<?php endif; ?>

			<?php else : ?>
				<h1 class="hd-auth-title"><?php echo $hd_pwreset ? esc_html__( 'رمز عبور تغییر کرد', 'hedayati' ) : esc_html__( 'ورود به سامانه', 'hedayati' ); ?></h1>
				<p class="hd-auth-lead">
					<?php echo $hd_pwreset
						? esc_html__( 'اکنون با رمز عبور جدید وارد شوید.', 'hedayati' )
						: esc_html__( 'برای دسترسی به پنل آموزشی یا حساب دانشجویی خود وارد شوید.', 'hedayati' ); ?>
				</p>
				<form class="hd-auth-form" method="post" action="<?php echo esc_url( Hedayati_Login::url() ); ?>">
					<?php wp_nonce_field( 'hedayati_login', 'hd_login_nonce' ); ?>
					<?php if ( '' !== $hd_redirect ) : ?>
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $hd_redirect ); ?>">
					<?php endif; ?>
					<label class="hd-auth-field">
						<span><?php esc_html_e( 'نام کاربری یا شمارهٔ همراه', 'hedayati' ); ?></span>
						<input type="text" name="log" value="<?php echo esc_attr( $hd_login_field ); ?>" autocomplete="username" required autofocus dir="ltr">
					</label>
					<label class="hd-auth-field">
						<span><?php esc_html_e( 'رمز عبور', 'hedayati' ); ?></span>
						<input type="password" name="pwd" autocomplete="current-password" required dir="ltr">
					</label>
					<label class="hd-auth-remember">
						<input type="checkbox" name="rememberme" value="forever">
						<span><?php esc_html_e( 'مرا به خاطر بسپار', 'hedayati' ); ?></span>
					</label>
					<button type="submit" class="hd-auth-btn"><?php esc_html_e( 'ورود', 'hedayati' ); ?></button>
				</form>
				<p><a class="hd-auth-link" href="<?php echo esc_url( Hedayati_Login::url( [ 'action' => 'lostpassword' ] ) ); ?>"><?php esc_html_e( 'رمز عبور خود را فراموش کرده‌اید؟', 'hedayati' ); ?></a></p>
			<?php endif; ?>

			<p class="hd-auth-foot">
				<?php esc_html_e( 'حساب دانشجویی توسط واحد پذیرش ایجاد می‌شود.', 'hedayati' ); ?>
				<a class="hd-auth-link" href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"><?php esc_html_e( 'مشاورهٔ انتخاب دوره', 'hedayati' ); ?></a>
			</p>
		</div>
	</div>
</main>
<?php
get_footer();
