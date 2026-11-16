// Build only the shared archive entry through the native legacy Babel rules.
const path = require('path');
const webpack = require('webpack');
const config = require('../webpack.legacy.dev.js');
const root = path.resolve(__dirname, '..');
const entry = 'js/admin/builder-archive';
config.context = root;
config.entry = { [entry]: config.entry[entry] };
config.mode = 'production';
config.devtool = false;
// NODE_PATH permits an isolated build cache without changing repository deps.
const additionalModules = (process.env.NODE_PATH || '')
	.split(path.delimiter)
	.filter(Boolean);
config.resolve.modules = [...additionalModules, 'node_modules'];
config.resolveLoader = { modules: [...additionalModules, 'node_modules'] };
webpack(config, (error, stats) => {
	if (error || stats.hasErrors()) {
		console.error(error || stats.toString({ all: false, errors: true }));
		process.exitCode = 1;
		return;
	}
	console.log(stats.toString({ all: false, assets: true, warnings: true }));
});
