window.DustPress = ( function( window, document, $ ) {

	var dp = {};

	dp.defaults = {
		"type": 	"post",
		"tidy": 	false,
		"render": 	false,
		"partial": 	""
	};

	dp.ajax = function( path, params, success, error ) {

		var post = $.extend( dp.defaults, params );

		dp.success 	= success;
		dp.error 	= error;

		$.ajax({
			url: window.location,
			method: post.type,
			data: {
				dustpress_data: {
					path: 	path,
					args: 	post.args,
					render: post.render,
					tidy: 	post.tidy
				}
			}
		})
		.done(dp.successHandler)
		.fail(dp.errorHandler);

	};

	dp.successHandler = function(data, textStatus, jqXHR) {
		var parsed = $.parseJSON(data);
		if(parsed.error === undefined)
			dp.success(parsed.success, textStatus, jqXHR);
		else
			dp.error(parsed.error, textStatus, jqXHR);
	};

	dp.errorHandler = function(jqXHR, textStatus, errorThrown) {
		dp.error({error: errorThrown}, textStatus, jqXHR);
	};

	return dp;

})( window, document, jQuery );

var dp = window.DustPress.ajax;
