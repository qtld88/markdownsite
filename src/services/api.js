import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (p) => generateUrl('/apps/markdownsite' + p)
const encPath = (p) => String(p).split('/').map(encodeURIComponent).join('/')

export const listSites = () => axios.get(base('/sites')).then(r => r.data)
export const createSite = (payload) => axios.post(base('/sites'), payload).then(r => r.data)
export const deleteSite = (id) => axios.delete(base(`/sites/${id}`)).then(r => r.data)
export const shareSite = (id, shares) => axios.post(base(`/sites/${id}/share`), { shares }).then(r => r.data)
export const getShares = (id) => axios.get(base(`/sites/${id}/shares`)).then(r => r.data)
export const searchSharees = (query) => axios.get(base('/sharees'), { params: { search: query } }).then(r => r.data)
export const getTree = (siteId) => axios.get(base(`/s/${siteId}/tree`)).then(r => r.data)
export const getPage = (siteId, path) => axios.get(base(`/s/${siteId}/page/${encPath(path)}`)).then(r => r.data)
export const assetUrl = (siteId, path) => base(`/s/${siteId}/file/${encPath(path)}`)
export const getPrefs = () => axios.get(base('/prefs')).then(r => r.data)
export const savePrefs = (p) => axios.put(base('/prefs'), p).then(r => r.data)
export const searchPages = (params, signal) => axios.get(base('/search'), { params, signal }).then(r => r.data)
