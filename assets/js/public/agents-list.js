// agents-list.js — NeoWeaver
/* global twAgentsData */

( function () {
	'use strict';

	const defaultAvatar = ( typeof twAgentsData !== 'undefined' && twAgentsData.defaultAvatar )
		? twAgentsData.defaultAvatar
		: null;

	function buildCard( data ) {
		const hp       = data.hp        ? parseInt( data.hp, 10 )       : null;
		const raceName  = data.cyber_races  && data.cyber_races.name  ? data.cyber_races.name  : 'Human';
		const className = data.cyber_classes && data.cyber_classes.name ? data.cyber_classes.name : 'Operative';
		const avatar    = data.avatar ? data.avatar : defaultAvatar;

		const tags      = Array.isArray(data.tags)      ? data.tags      : [];
		const inventory = Array.isArray(data.inventory) ? data.inventory : [];

		return { hp, raceName, className, avatar, tags, inventory };
	}

	window.nwBuildCard = buildCard;
} )();
