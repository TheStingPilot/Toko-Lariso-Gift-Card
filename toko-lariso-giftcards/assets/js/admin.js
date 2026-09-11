(function ($) {
	'use strict';

	$('.tokolariso-color-field').wpColorPicker();

	$(document).on('click', '[data-tokolariso-media-select]', function (event) {
		event.preventDefault();

		var $button = $(this);
		var target = $button.data('target');
		var preview = $button.data('preview');
		var frame = wp.media({
			title: 'Choose PDF logo',
			button: {
				text: 'Use this logo'
			},
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var imageUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

			$(target).val(attachment.id);
			$(preview).html('<img src="' + imageUrl + '" alt="" />');
		});

		frame.open();
	});

	$(document).on('click', '[data-tokolariso-media-clear]', function (event) {
		event.preventDefault();

		$($(this).data('target')).val('');
		$($(this).data('preview')).empty();
	});
})(jQuery);
