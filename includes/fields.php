<?php
// 🇨🇳 WeChat QR Display for Chinese Users
add_action('woocommerce_after_checkout_billing_form', 'sd_add_wechat_qr');
function sd_add_wechat_qr() {
    $qr_path = plugin_dir_url(__FILE__) . '../assets/qr-code.png';

    echo '
    <div id="wechat-qr-section" style="display:none; margin-top:20px; border:1px solid #ccc; padding:15px; border-radius:8px;">
        <h3 style="margin-top:0;">WeChat Users</h3>
        <p>If you\'re from China and prefer WeChat, scan the QR code below to connect with us.</p>
        <img src="' . esc_url($qr_path) . '" alt="WeChat QR Code" style="max-width:200px; display:block; margin-bottom:10px;">
        <a href="' . esc_url($qr_path) . '" download="WeChat-QR-Code.png" class="button" style="text-decoration:none; padding:8px 12px; background:#007cba; color:#fff; border-radius:4px;">Download QR Code</a>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var phoneField = document.querySelector("#billing_phone");
            var qrSection = document.querySelector("#wechat-qr-section");

            function toggleQR() {
                var phone = phoneField?.value.trim() || "";
                qrSection.style.display = (phone === "" || phone.startsWith("+86")) ? "block" : "none";
            }

            toggleQR();
            phoneField?.addEventListener("input", toggleQR);
        });
    </script>';
}
