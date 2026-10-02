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

// Editing (owners and editors). Statuses the editor handles itself
// (403, 404, 409) resolve with {status, ...data}; network failures reject.
const handled = (e, statuses) => {
	if (e.response && statuses.includes(e.response.status)) {
		return { status: e.response.status, ...e.response.data }
	}
	throw e
}
export const getSource = (siteId, path) => axios.get(base(`/s/${siteId}/source/${encPath(path)}`)).then(r => r.data)
export const savePage = (siteId, path, content, etag) => axios.put(base(`/s/${siteId}/page/${encPath(path)}`), { content, etag })
	.then(r => ({ status: r.status, ...r.data }), e => handled(e, [403, 404, 409]))
export const createPage = (siteId, path) => axios.post(base(`/s/${siteId}/pages`), { path }).then(r => r.data)
export const createFolder = (siteId, path) => axios.post(base(`/s/${siteId}/folders`), { path }).then(r => r.data)
export const getBacklinks = (siteId, path, to) => axios.get(base(`/s/${siteId}/backlinks/${encPath(path)}`), { params: { to } }).then(r => r.data)
export const moveNode = (siteId, from, to, updateLinks) => axios.post(base(`/s/${siteId}/move`), { from, to, updateLinks }).then(r => r.data)
export const deleteNode = (siteId, path) => axios.delete(base(`/s/${siteId}/node/${encPath(path)}`)).then(r => r.data)
export const uploadAttachment = (siteId, page, file) => {
	const form = new FormData()
	form.append('file', file)
	form.append('page', page)
	return axios.post(base(`/s/${siteId}/attachments`), form).then(r => r.data)
}
export const renderMarkdown = (siteId, path, markdown) => axios.post(base(`/s/${siteId}/render`), { markdown, path }).then(r => r.data.html)
export const updateShareRole = (siteId, shareId, role) => axios.put(base(`/sites/${siteId}/shares/${shareId}`), { role }).then(r => r.data)
