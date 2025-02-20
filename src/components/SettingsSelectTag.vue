<!--
  - @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<Multiselect v-model="inputValObjects"
		:options="tags"
		:options-limit="5"
		:placeholder="label"
		track-by="id"
		:custom-label="tagLabel"
		class="multiselect-vue"
		:multiple="true"
		:close-on-select="false"
		:tag-width="60"
		:disabled="disabled"
		@input="update"
		@search-change="asyncFind">
		<span slot="noResult">{{ t('settings', 'No results') }}</span>
		<template #option="scope">
			{{ tagLabel(scope.option) }}
		</template>
	</Multiselect>
</template>

<script>
import axios from '@nextcloud/axios'

import { generateRemoteUrl } from '@nextcloud/router'
import { Multiselect } from '@nextcloud/vue'

const xmlToJson = (xml) => {
	let obj = {}

	if (xml.nodeType === 1) {
		if (xml.attributes.length > 0) {
			obj['@attributes'] = {}
			for (let j = 0; j < xml.attributes.length; j++) {
				const attribute = xml.attributes.item(j)
				obj['@attributes'][attribute.nodeName] = attribute.nodeValue
			}
		}
	} else if (xml.nodeType === 3) {
		obj = xml.nodeValue
	}

	if (xml.hasChildNodes()) {
		for (let i = 0; i < xml.childNodes.length; i++) {
			const item = xml.childNodes.item(i)
			const nodeName = item.nodeName
			if (typeof (obj[nodeName]) === 'undefined') {
				obj[nodeName] = xmlToJson(item)
			} else {
				if (typeof obj[nodeName].push === 'undefined') {
					const old = obj[nodeName]
					obj[nodeName] = []
					obj[nodeName].push(old)
				}
				obj[nodeName].push(xmlToJson(item))
			}
		}
	}
	return obj
}

const parseXml = (xml) => {
	let dom = null
	try {
		dom = (new DOMParser()).parseFromString(xml, 'text/xml')
	} catch (e) {
		console.error('Failed to parse xml document', e)
	}
	return dom
}

const xmlToTagList = (xml) => {
	const json = xmlToJson(parseXml(xml))
	const list = json['d:multistatus']['d:response']
	const result = []
	for (const index in list) {
		const tag = list[index]['d:propstat']

		if (tag['d:status']['#text'] !== 'HTTP/1.1 200 OK') {
			continue
		}
		result.push({
			id: tag['d:prop']['oc:id']['#text'],
			displayName: tag['d:prop']['oc:display-name']['#text'],
			canAssign: tag['d:prop']['oc:can-assign']['#text'] === 'true',
			userAssignable: tag['d:prop']['oc:user-assignable']['#text'] === 'true',
			userVisible: tag['d:prop']['oc:user-visible']['#text'] === 'true',
		})
	}
	return result
}

const searchTags = function() {
	return axios({
		method: 'PROPFIND',
		url: generateRemoteUrl('dav') + '/systemtags/',
		data: `<?xml version="1.0"?>
					<d:propfind  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
					  <d:prop>
						<oc:id />
						<oc:display-name />
						<oc:user-visible />
						<oc:user-assignable />
						<oc:can-assign />
					  </d:prop>
					</d:propfind>`,
	}).then((response) => {
		return xmlToTagList(response.data)
	})
}

let uuid = 0
export default {
	name: 'SettingsSelectTag',
	components: {
		Multiselect,
	},
	props: {
		label: {
			type: String,
			required: true,
		},
		hint: {
			type: String,
			default: '',
		},
		value: {
			type: Array,
			default: () => [],
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			inputValObjects: [],
			tags: [],
		}
	},
	computed: {
		id() {
			return 'settings-input-text-' + this.uuid
		},
	},
	watch: {
		value(newVal) {
			this.inputValObjects = this.getValueObject()
		},
	},
	beforeCreate() {
		this.uuid = uuid.toString()
		uuid += 1
		searchTags().then((result) => {
			this.tags = result
			this.inputValObjects = this.getValueObject()
		})
	},
	methods: {
		asyncFind(query) {
		},
		getValueObject() {
			return this.value.filter((tag) => tag !== '').map(
				(id) => this.tags.find((tag) => tag.id === id)
			)
		},
		update() {
			this.$emit('input', this.inputValObjects.map((element) => element.id))
		},
		tagLabel({ displayName, userVisible, userAssignable }) {
			if (userVisible === false) {
				return `${displayName} (invisible)`
			}
			if (userAssignable === false) {
				return `${displayName} (restricted)`
			}
			return displayName
		},
	},
}
</script>
