import { KalturaClient } from "../kaltura-client-service";
import { UiConfListAction } from "../api/types/UiConfListAction";
import { KalturaUiConfListResponse } from "../api/types/KalturaUiConfListResponse";
import { KalturaUiConf } from "../api/types/KalturaUiConf";
import { KalturaUiConfFilter } from "../api/types/KalturaUiConfFilter";
import { KalturaUiConfObjType } from "../api/types/KalturaUiConfObjType";
import { UiConfListTemplatesAction } from "../api/types/UiConfListTemplatesAction";
import { getClient } from "./utils";
import { LoggerSettings, LogLevels } from "../api/kaltura-logger";

describe(`service "UIConf" tests`, () => {
  let kalturaClient: KalturaClient = null;

  beforeAll(async () => {
    LoggerSettings.logLevel = LogLevels.error; // suspend warnings

    return getClient()
      .then(client => {
        kalturaClient = client;
      }).catch(error => {
        // can do nothing since jasmine will ignore any exceptions thrown from before all
      });
  });

  afterAll(() => {
    kalturaClient = null;
  });

  test("uiconf list", (done) => {
    kalturaClient.request(new UiConfListAction())
      .then(
        response => {
          expect(response instanceof KalturaUiConfListResponse).toBeTruthy();
          expect(Array.isArray(response.objects)).toBeTruthy();
          expect(response.objects.every(obj => obj instanceof KalturaUiConf)).toBeTruthy();
          done();
        },
        (error) => {
          fail(error);
          done();
        }
      );
  });


  // TODO [kmc] investigate response
  xtest("get players", (done) => {
    const players = [KalturaUiConfObjType.player, KalturaUiConfObjType.playerV3, KalturaUiConfObjType.playerSl];
    const filter = new KalturaUiConfFilter({ objTypeIn: players.join(",") });

    kalturaClient.request(new UiConfListAction(filter))
      .then(
        response => {
          expect(response.objects.every(obj => players.indexOf(Number(obj.objType)) !== -1)).toBeTruthy();
          done();
        },
        (error) => {
          fail(error);
          done();
        }
      );
  });

  test("get video players", (done) => {
    const players = [KalturaUiConfObjType.player, KalturaUiConfObjType.playerV3, KalturaUiConfObjType.playerSl];
    const filter = new KalturaUiConfFilter({
      objTypeIn: players.join(","),
      tagsMultiLikeOr: "player"
    });

    kalturaClient.request(new UiConfListAction(filter))
      .then(
        response => {
          response.objects.forEach(obj => {
            // TODO [kmc] blocked by previous test-case
            // expect(players.indexOf(Number(obj.objType)) !== -1).toBeTruthy();
            const match = /isPlaylist="(.*?)"/g.exec(obj.confFile);
            if (match) {
              expect(["true", "multi"].indexOf(match[1]) !== -1).toBeTruthy();
            }
          });


          done();
        },
        (error) => {
          fail(error);
          done();
        }
      );
  });

  test("uiconf list templates", (done) => {
    kalturaClient.request(new UiConfListTemplatesAction())
      .then(
        response => {
          expect(response instanceof KalturaUiConfListResponse).toBeTruthy();
          expect(Array.isArray(response.objects)).toBeTruthy();
          expect(response.objects.every(obj => obj instanceof KalturaUiConf)).toBeTruthy();
          done();
        },
        (error) => {
          fail(error);
          done();
        }
      );
  });
});
