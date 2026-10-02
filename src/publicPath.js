import { generateFilePath } from '@nextcloud/router'

// Lazy chunks must load from where Nextcloud actually serves this app's js/
// (often custom_apps/), not from the build-time default /apps/markdownsite/js/.
// eslint-disable-next-line no-undef, camelcase
__webpack_public_path__ = generateFilePath('markdownsite', '', 'js/')
