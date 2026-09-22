import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	admin: 'src/admin.js',
	personal: 'src/personal.js',
})
