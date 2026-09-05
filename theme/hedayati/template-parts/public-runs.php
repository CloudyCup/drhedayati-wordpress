<?php
if ( ! class_exists( 'Hedayati_Public_Content' ) ) { return; }
$hd_runs = Hedayati_Public_Content::runs( get_the_ID() );
if ( ! $hd_runs ) { return; }
?>
<section class="section container"><h2>کلاس‌های پیش رو</h2><div class="hd-public-grid">
<?php foreach ( $hd_runs as $hd_run ) : ?>
 <article class="hd-public-card">
 <h3><?php echo esc_html( $hd_run['start_date'] ? Hedayati_Jalali::format( $hd_run['start_date'] ) : 'تاریخ متعاقباً اعلام می‌شود' ); ?></h3>
 <?php if ( null !== $hd_run['tuition_rial'] ) : ?><p><?php echo esc_html( Hedayati_Text::digits_to_persian( number_format( $hd_run['tuition_rial'] ) ) ); ?> ریال</p><?php endif; ?>
 <p><?php echo esc_html( [ 'open' => 'ثبت‌نام باز', 'closed' => 'ثبت‌نام بسته', 'soon' => 'ثبت‌نام به‌زودی' ][ $hd_run['registration_status'] ] ?? '' ); ?></p>
 <a class="outline-btn" href="<?php echo esc_url( home_url( '/consult/' ) ); ?>">پرسش درباره این کلاس</a>
 </article>
<?php endforeach; ?>
</div></section>
