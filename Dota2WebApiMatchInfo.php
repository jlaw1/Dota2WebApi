<?php

class Dota2WebApiException extends Exception {

}

class Dota2WebApiMatchInfo {

	private $_url;
	private $_match_id;
	private $_match_details;
	private $_player_summaries;
	private $_player_names;
	private $_result;
	private $_cacheMaxAge = 3600;
	private $cond_picks_bans = false, $cond_kills_deaths = false, $cond_players = false,
		$cond_duration = false, $cond_radiant_win = false, $cond_teams = false,
		$cond_start_time = false;

	const match_details_url = 'https://api.steampowered.com/IDOTA2Match_570/GetMatchDetails/v001/';
	const player_summaries_url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/';

	public function getMatchInfo( $params ) {


		$this->_result = new Dota2WebApiResult();
		$this->parseParams( $params );


		if ( $this->_match_id <= 0 ) {
			throw new Dota2WebApiException( wfMessage( 'dota2webapi-error-non-strictly-positive-match-id' )->text() );
		}
		global $wgDota2WebApiKey;

		if ( !$this->hasCachedAPIResult() ) {
			$this->checkApiKey();
			$this->sendMatchDetailsRequest();
			$this->checkMatchDetailsResult();
			$this->getMatchData();
			$this->cacheAPIResult();
		}

		return $this->_result;
	}

	private function parseParams( $params ) {
		$this->_match_id = $params[ 'matchid' ];

		$cond = array_flip( $params[ 'data' ] );
		$this->cond_picks_bans = isset( $cond[ 'picks_bans' ] );
		$this->cond_kills_deaths = isset( $cond[ 'kills_deaths' ] );
		$this->cond_deaths = isset( $cond[ 'deaths' ] );
		$this->cond_players = isset( $cond[ 'players' ] );
		$this->cond_duration = isset( $cond[ 'duration' ] );
		$this->cond_radiant_win = isset( $cond[ 'radiant_win' ] );
		$this->cond_teams = isset( $cond[ 'teams' ] );
		$this->cond_start_time = isset( $cond[ 'start_time' ] );
	}

	private function checkApiKey() {
		global $wgDota2WebApiKey;

		if ( !$wgDota2WebApiKey ) {
			throw new Dota2WebApiException( wfMessage( 'dota2webapi-error-missing-api-key' )->text() );
		}
	}

	private function sendMatchDetailsRequest() {
		global $wgDota2WebApiKey;

		$this->_url = self::requestUrl( self::match_details_url, [
				'key' => $wgDota2WebApiKey,
				'match_id' => $this->_match_id
			] );
		$this->_match_details = $this->sendRequest();
	}

	private function checkMatchDetailsResult() {
		if ( $this->_match_details == null ) {
			throw new Dota2WebApiException( wfMessage( 'dota2webapi-error-no-valve-api-data' )->text() );
		} elseif ( isset( $this->_match_details->result->error ) ) {
			throw new Dota2WebApiException( wfMessage( 'dota2webapi-error-message-from-valve-api' )->text()
			. "\n" . $this->_match_details->result->error );
		}
	}

	private function getMatchData() {
		if ( $this->cond_picks_bans ) {
			self::getPicksAndBans();
		}
		if ( $this->cond_kills_deaths ) {
			self::getKillsAndDeaths();
		}
		if ( $this->cond_players ) {
			self::getPlayerNames();
			self::getPlayers();
		}
		if ( $this->cond_duration ) {
			self::getDuration();
		}
		if ( $this->cond_radiant_win ) {
			self::getRadiantWin();
		}
		if ( $this->cond_teams ) {
			self::getTeams();
		}
		if ( $this->cond_start_time ) {
			self::getStartTime();
		}
	}

	private function requestUrl( $base, $params ) {
		$q = [];
		foreach ( $params as $key => $value ) {
			$q[] = $key . '=' . $value;
		}

		return $base . '?' . implode( '&', $q );
	}

	private function sendRequest() {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->_url );
		curl_setopt( $ch, CURLOPT_ENCODING, '' );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; Dota2WebApi/1.0; liquipedia@teamliquid.net)" );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, (CURLPROTO_HTTP | CURLPROTO_HTTPS ) );
		curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, (CURLPROTO_HTTP | CURLPROTO_HTTPS ) );

		$res = curl_exec( $ch );
		curl_close( $ch );
		return json_decode( $res );
	}

	private function getPicksAndBans() {
		$picks_bans_count = [
			'radiant' => [
				'pick' => 0,
				'ban' => 0
			],
			'dire' => [
				'pick' => 0,
				'ban' => 0
			]
		];
		$this->_result->picks_bans = [
			'radiant' => [],
			'dire' => []
		];
		if ( isset( $this->_match_details->result->picks_bans ) ) {
			foreach ( $this->_match_details->result->picks_bans as $pick_ban ) {
				if ( $pick_ban->team == 0 ) {
					$team = 'radiant';
				} else {
					$team = 'dire';
				}
				if ( $pick_ban->is_pick ) {
					$type = 'pick';
				} else {
					$type = 'ban';
				}
				$count = ++$picks_bans_count[ $team ][ $type ];
				$this->_result->picks_bans[ $team ][ $type . '_' . $count ] = $pick_ban->hero_id;
			}
		}
	}

	private function getKillsAndDeaths() {
		$this->_result->kills = [
			'radiant' => 0,
			'dire' => 0
		];
		$this->_result->deaths = $this->_result->kills;
		foreach ( $this->_match_details->result->players as $player ) {
			if ( $player->player_slot < 128 ) {
				$team = 'radiant';
			} else {
				$team = 'dire';
			}
			$this->_result->kills[ $team ] += $player->kills;
			$this->_result->deaths[ $team ] += $player->deaths;
		}
	}

	private function getPlayerNames() {
		global $wgDota2WebApiKey;

		$steam_ids = [];
		foreach ( $this->_match_details->result->players as $player ) {
			if ( $player->account_id != Dota2WebApiPlayer::ANONYMOUS ) {
				$steam_ids[] = Dota2WebApiPlayer::convertId( $player->account_id );
			}
		}

		$this->_url = self::requestUrl( self::player_summaries_url, [
				'key' => $wgDota2WebApiKey,
				'steamids' => implode( ',', $steam_ids )
			] );
		$this->_player_summaries = $this->sendRequest();

		$this->_persona_names = [];
		foreach ( $this->_player_summaries->response->players as $player ) {
			$this->_persona_names[ $player->steamid ] = $player->personaname;
		}
	}

	private function getPlayers() {
		$this->_result->players = [
			'radiant' => [],
			'dire' => []
		];
		foreach ( $this->_match_details->result->players as $player ) {
			if ( $player->player_slot < 128 ) {
				$team = 'radiant';
				$team_slot = $player->player_slot + 1;
			} else {
				$team = 'dire';
				$team_slot = $player->player_slot - 127;
			}

			$this->_result->players[ $team ][ 'player_' . $team_slot ] = $this->getPlayerDetails( $player );
		}
	}

	private function getPlayerDetails( $player ) {
		$playerDetails = new Dota2WebApiPlayer();
		$playerDetails->setPersonaNames( $this->_persona_names );
		$playerDetails->setData( $player );
		return $playerDetails;
	}

	private function getDuration() {
		$duration = $this->_match_details->result->duration;
		$this->_result->duration = sprintf( '%02dm%02ds', $duration / 60, $duration % 60 );
	}

	private function getRadiantWin() {
		$this->_result->radiant_win = $this->_match_details->result->radiant_win;
	}

	private function getTeams() {
		$this->_result->teams = [
			'radiant' => isset( $this->_match_details->result->radiant_name ) ?
			$this->_match_details->result->radiant_name : 'tbd',
			'dire' => isset( $this->_match_details->result->dire_name ) ?
			$this->_match_details->result->dire_name : 'tbd',
		];
	}

	private function getStartTime() {
		$this->_result->start_time = gmdate( 'F j, Y - H:i \{\{\A\b\b\r\/\U\T\C\}\}', $this->_match_details->result->start_time );
	}

	private function hasCachedAPIResult() {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'dota2webapicache', '*', [ 'matchid' => $this->_match_id ] );
		if ( $row = $res->fetchObject() ) {
			if ( $row->timestamp < time() - $this->_cacheMaxAge ) {
				return false;
			} elseif ( $row->timestamp >= time() - $this->_cacheMaxAge ) {
				$this->_result = unserialize( $row->apiresult );
				return true;
			}
		}
		return false;
	}

	private function cacheAPIResult() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'dota2webapicache', [ 'matchid' => $this->_match_id ] );
		if ( isset( $this->_match_details->result->error ) ) {
			$dbw->insert( 'dota2webapicache', [ [ 'matchid' => $this->_match_id, 'apiresult' => serialize( $this->_result ), 'timestamp' => time() ] ] );
		}
	}

}
