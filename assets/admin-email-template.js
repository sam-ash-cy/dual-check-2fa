/**
 * Email template screen: colour sync + Media Library image pickers.
 */
(function () {
	'use strict';

	function expand3(h) {
		if (/^#[0-9a-fA-F]{3}$/.test(h)) {
			return (
				'#' +
				h.charAt(1) +
				h.charAt(1) +
				h.charAt(2) +
				h.charAt(2) +
				h.charAt(3) +
				h.charAt(3)
			);
		}
		return h;
	}

	function applyTextToPicker(t, p) {
		var v = t.value.trim();
		if (/^#[0-9a-fA-F]{6}$/.test(v)) {
			p.value = v;
		} else if (/^#[0-9a-fA-F]{3}$/.test(v)) {
			p.value = expand3(v);
		}
	}

	document.querySelectorAll('[data-wdc-color-sync]').forEach(function (wrap) {
		var p = wrap.querySelector('input[type="color"]');
		var t = wrap.querySelector('input[type="text"]');
		if (!p || !t) {
			return;
		}

		applyTextToPicker(t, p);

		p.addEventListener('input', function () {
			t.value = p.value;
		});

		t.addEventListener('change', function () {
			applyTextToPicker(t, p);
		});

		t.addEventListener('input', function () {
			var v = t.value.trim();
			if (/^#[0-9a-fA-F]{6}$/.test(v)) {
				p.value = v;
			}
		});
	});
})();

(function ($) {
	'use strict';

	function attachmentUrl(attachment) {
		if (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) {
			return attachment.sizes.full.url;
		}
		return attachment.url || '';
	}

	$(function () {
		var i18n = window.wdcEmailTpl || {
			frameTitle: 'Choose image',
			frameButton: 'Use this image',
		};

		$('[data-wdc-media-url]').each(function () {
			var $field = $(this);
			var $input = $field.find('.wdc-media-url-input');
			var $previewWrap = $field.find('.wdc-media-preview-wrap');
			var $img = $field.find('.wdc-media-preview-img');
			var frame;

			function setPreview(url) {
				if (url) {
					$img.attr('src', url);
					$previewWrap.show();
				} else {
					$img.attr('src', '');
					$previewWrap.hide();
				}
			}

			$input.on('input change', function () {
				var u = $input.val().trim();
				if (u) {
					$img.attr('src', u);
					$previewWrap.show();
				} else {
					setPreview('');
				}
			});

			$field.find('.wdc-media-clear').on('click', function (e) {
				e.preventDefault();
				$input.val('');
				setPreview('');
			});

			$field.find('.wdc-media-select').on('click', function (e) {
				e.preventDefault();
				if (typeof wp === 'undefined' || !wp.media) {
					return;
				}
				if (frame) {
					frame.open();
					return;
				}
				frame = wp.media({
					title: i18n.frameTitle,
					library: { type: 'image' },
					button: { text: i18n.frameButton },
					multiple: false,
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var url = attachmentUrl(attachment);
					$input.val(url);
					setPreview(url);
				});
				frame.open();
			});
		});
	});
})(jQuery);
