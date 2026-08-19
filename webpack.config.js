/**
 * Webpack build for Souvera Shield.
 *
 * The Souvera Design System (SOUVERA_DESIGN_SYSTEM.md §4) mandates a
 * Webpack pipeline that emits `js/<appid>-<entry>.js` bundles which the
 * PHP template loads via `script('souvera_shield', 'souvera_shield-main')`.
 */
const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = {
    entry: {
        main: path.join(__dirname, 'src', 'main.js'),
    },
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: 'souvera_shield-[name].js',
        chunkFilename: 'souvera_shield-chunk-[name]-[contenthash].js',
        publicPath: 'auto',
        clean: true,
    },
    resolve: {
        extensions: ['.js', '.mjs', '.vue'],
        alias: {
            '@': path.resolve(__dirname, 'src'),
        },
    },
    module: {
        rules: [
            {
                test: /\.vue$/,
                loader: 'vue-loader',
            },
            {
                // @nextcloud/vue ships .mjs "fully specified" ESM.
                test: /\.m?js$/,
                resolve: { fullySpecified: false },
                exclude: /node_modules\/(?!(@nextcloud|vue-material-design-icons)\/)/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: [
                            ['@babel/preset-env', { targets: { esmodules: true } }],
                        ],
                        cacheDirectory: true,
                    },
                },
            },
            {
                test: /\.css$/,
                use: ['vue-style-loader', 'css-loader'],
            },
        ],
    },
    plugins: [
        new VueLoaderPlugin(),
        new webpack.DefinePlugin({
            __VUE_OPTIONS_API__: true,
            __VUE_PROD_DEVTOOLS__: false,
            __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
        }),
    ],
    performance: {
        hints: false,
    },
    stats: {
        errorDetails: true,
    },
}
