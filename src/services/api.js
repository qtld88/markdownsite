import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (p) => generateUrl('/apps/markdownsite' + p)

export const listSites = () => axios.get(base('/sites')).then(r => r.data)
export const createSite = (payload) => axios.post(base('/sites'), payload).then(r => r.data)
export const deleteSite = (id) => axios.delete(base(`/sites/${id}`)).then(r => r.data)
export const shareSite = (id, shares) => axios.post(base(`/sites/${id}/share`), { shares }).then(r => r.data)
export const getTree = (siteId) => axios.get(base(`/s/${siteId}/tree`)).then(r => r.data)
export const getPage = (siteId, path) => axios.get(base(`/s/${siteId}/page/${path}`)).then(r => r.data)
export const assetUrl = (siteId, path) => base(`/s/${siteId}/file/${path}`)
