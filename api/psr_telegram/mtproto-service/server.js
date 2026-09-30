const express = require("express");
const cors = require("cors");
const axios = require("axios");

const { TelegramClient } = require("telegram");
const { StringSession } = require("telegram/sessions");
const { Api } = require("telegram");
//const { NewMessage } = require("telegram/events");

const input = require("input");

const app = express();

app.use(cors());
app.use(express.json());

const apiId = 179779;
const apiHash = "cef35740b4d4728185f7d04bc05e2ea6";

// const stringSession = new StringSession("1BQANOTEuMTA4LjU2LjEwNQG7tXCSeMCod8yLBrue64Jf+5u1HtIfMdiHZ8OBKdBerZu9D2MPNRA5muCjADsaDdebS91z6FjND8CkpwGLo0R4tgZJcQnxNghC8RJrodb91fCfg4JsWIyxWaBm722REypfPQdGR2hY03MY0En6qjmNyAW6SUz/PQAAnc+3K7/AZPTk8U1jOqpaDZ+G5f/DfGHS5eCpBdiqP/xQX6OiT6Hb1cU2Yq9icGL6Fg9EJmg6YJr/woj0ySjJGbAeJg7HwuB9T9qghWO52Yhuj2TIoUBZMmHTlp456yW0u2wm6LQSSeU/XM5vVwRnxr5u4lILK914P0QxzKo6G3IrbnztPKowGA==");

const stringSession = new StringSession("1BQANOTEuMTA4LjU2LjEwNQG7lIiJ7bob8f1son2TLH7+Lee0Z5UIARsI8V6LeSGJJKl8AJcY02jkeGgE+/GoNCEr9ZJLvny13ezFfoKCNhG6NZNblBvvVmf2u6TceGJ+9hsbiCAiljIMq8DwX0SUmNi9i3T/GE+DY2xpM0366Q16raKOsK8guW5CNX2cgHcPqBuHBsw6SYeuJTshJMjO09Kmf3N6T/DY/YbnSEr94AZeYT+ee+15NDk2DABQGnNDPsiDUqDmZ9nNfpeFeJFa+U70/t/5RxQexKZibneGw8Y9zAL2UDhbpe5NBn2NpkM3QPRHm+lHfewGVsksmfcUB5GeqkK/foaoABjCdLImXC5NJw==");

const client = new TelegramClient(
  stringSession,
  apiId,
  apiHash,
  {
    connectionRetries: 5,
    receiveUpdates: true,
  }
);

async function initTelegram() {
  await client.start({
    phoneNumber: async () => await input.text("Phone: "),
    password: async () => await input.text("Password: "),
    phoneCode: async () => await input.text("Code: "),
    onError: (err) => console.log(err),
  });

  await client.connect();

  console.log("Telegram Connected");

  console.log(client.session.save());

  // REGISTER EVENTS
  registerTelegramEvents();

  console.log("Telegram Events Registered")
}

initTelegram();

// function registerTelegramEvents() {

//   client.addEventHandler(async (update) => {

    
//     try {
//       console.log("RAW UPDATE RECEIVED");
//       //console.log(JSON.stringify(update, null, 2));
//       console.log({
//         className: update.className,
//         messageId: update.message?.id,
//       });
//       // Channel/group messages
//       if (
//         update.className === "UpdateNewChannelMessage"
//       ) {
//         const message = update.message;
//         if (!message) return;

//         // USER JOINED
//         if (
//           message.action &&
//           (
//             message.action.className === "MessageActionChatAddUser" ||
//             message.action.className === "MessageActionChatJoinedByLink"
//           )
//         ) {
//            console.log("USER JOIN DETECTED");
//           let userId = null;
//           // Joined via invite link
//           if (
//             message.action.className ===
//             "MessageActionChatJoinedByLink"
//           ) {
//             userId = message.fromId.userId;
//           }
//           // Added manually
//           if (
//             message.action.className ===
//             "MessageActionChatAddUser"
//           ) {
//             userId = message.action.users[0];
//           }
//           console.log("USER ID:", userId);
//           const user = await client.getEntity(userId);
//           console.log("USER DETAILS:", {
//             id: user.id,
//             username: user.username,
//             firstName: user.firstName,
//             lastName: user.lastName,
//           });

//           const chat = await client.getEntity(
//             message.peerId
//           );

//           console.log("GROUP:", {
//             id: chat.id,
//             title: chat.title,
//           });

//           // GET INVITE IMPORTERS
//           try {

//             const me = await client.getMe();

//             const importers = await client.invoke(
//               new Api.messages.GetChatInviteImporters({
//                 peer: chat,
//                 requested: false,
//                 subscriptionExpired: false,
//                 offsetDate: 0,
//                 offsetUser: new Api.InputUserSelf(),
//                 limit: 50
//               })
//             );

//             console.log(
//               "IMPORTERS DATA:",
//               JSON.stringify(importers, null, 2)
//             );

//           } catch (e) {

//             console.log(
//               "IMPORTERS ERROR:",
//               e
//             );

//           }
//         }
//       }
//     } catch (err) {
//       console.log("EVENT ERROR:", err);
//     }
//   });
// }

function registerTelegramEvents() {
  client.addEventHandler(async (update) => {
    try {
      if (update.className !== "UpdateNewChannelMessage") {
        return;
      }

      const message = update.message;
      if (!message?.action) {
        return;
      }

      let userId = null;
      if ( message.action.className === "MessageActionChatJoinedByLink" ) {
        userId = message.fromId?.userId;
      }

      if ( message.action.className === "MessageActionChatAddUser" ) {
        userId = message.action.users?.[0];
      }

      if (!userId) {
        return;
      }

      const user = await client.getEntity(userId);
      const group = await client.getEntity(message.peerId);

      const telegramUserId = user.id.toString();
      const telegramGroupId = group.id.toString();

      console.log("USER JOINED", {
        telegramUserId,
        telegramGroupId,
        firstName: user.firstName,
        lastName: user.lastName,
      });

      try {
        const response = await axios.post(
          "http://localhost:8080/sdlhub/sdlhub_new/backend/api/psr_telegram/api/telegram/members/group-joined",
          {
            telegram_user_id: telegramUserId,
            telegram_group_id: telegramGroupId
          }
        );

        console.log( "GROUP JOIN UPDATED", response.data );
      } catch (err) {
        console.log( "GROUP JOIN API ERROR", err.message );
      }
    } catch (err) {
      console.log("EVENT ERROR:", err);
    }
  });
}

app.post("/create-group", async (req, res) => {
  try {
    console.log("=======Req body", req.body);

    const { group_name, description } = req.body;

    if (!group_name) {
      return res.status(400).json({
        success: false,
        message: "group_name is required",
      });
    }

    // Create Supergroup
    const result = await client.invoke(
      new Api.channels.CreateChannel({
        title: group_name,
        about: description || "",
        megagroup: true,
      })
    );

    const groupEntity = result.chats[0];

    // Export Invite Link
    const invite = await client.invoke(
      new Api.messages.ExportChatInvite({
        peer: groupEntity,
      })
    );

    // Get Bot Entity
    const botEntity = await client.getEntity("sdlitTechBot");

    // Add Bot to Group
    await client.invoke(
      new Api.channels.InviteToChannel({
        channel: groupEntity,
        users: [botEntity],
      })
    );

    // Make Bot Admin (important)
    // await client.invoke(
    //   new Api.channels.EditAdmin({
    //     channel: groupEntity,
    //     userId: botEntity,
    //     adminRights: new Api.ChatAdminRights({
    //       //postMessages: true,
    //       sendMessages: true,
    //       addAdmins: false,
    //       inviteUsers: true,
    //       changeInfo: false,
    //       banUsers: false,
    //       deleteMessages: false,
    //       pinMessages: true,
    //       manageCall: false,
    //       anonymous: false,
    //       manageTopics: true,
    //     }),
    //     rank: "Bot",
    //   })
    // );

    await client.invoke(
      new Api.channels.EditAdmin({
        channel: groupEntity,
        userId: botEntity,
        adminRights: new Api.ChatAdminRights({
          sendMessages: true,
          inviteUsers: true,
          pinMessages: true,
          manageTopics: true,
        }),
        rank: "Bot",
      })
    );

    // Restrict ALL normal members from sending messages
    // await client.invoke(
    //   new Api.messages.ToggleNoForwards({
    //     peer: groupEntity,
    //     enabled: false,
    //   })
    // );

    // Set default banned rights
    await client.invoke(
      new Api.messages.EditChatDefaultBannedRights({
        peer: groupEntity,
        bannedRights: new Api.ChatBannedRights({
          untilDate: 0,

          sendMessages: true,
          sendMedia: true,
          sendStickers: true,
          sendGifs: true,
          sendGames: true,
          sendInline: true,
          embedLinks: true,
          sendPolls: true,
        }),
      })
    );

    res.json({
      success: true,
      data: {
        telegram_group_id: groupEntity.id,
        title: groupEntity.title,
        invite_link: invite.link,
      },
    });

  } catch (err) {
    console.log(err);

    res.status(500).json({
      success: false,
      message: err.message,
    });
  }
});


app.post("/generate-employee-invite", async (req, res) => {
  try {

    const {
      telegram_group_id,
      employee_code
    } = req.body;

    const groupEntity = await client.getEntity(
      Number(telegram_group_id)
    );

    const invite = await client.invoke(
      new Api.messages.ExportChatInvite({
        peer: groupEntity,

        // one employee = one link
        usageLimit: 1,

        // optional expiry after 7 days
        expireDate:
          Math.floor(Date.now() / 1000) +
          (7 * 24 * 60 * 60)
      })
    );

    const inviteLink = invite.link;

    const inviteHash =
      inviteLink.includes("+")
        ? inviteLink.split("+")[1]
        : inviteLink;

    console.log("EMPLOYEE:", employee_code);
    console.log("INVITE:", inviteLink);

    res.json({
      success: true,
      data: {
        employee_code,
        telegram_group_id,
        invite_link: inviteLink,
        invite_hash: inviteHash
      }
    });

  } catch (err) {

    console.log(err);

    res.status(500).json({
      success: false,
      message: err.message
    });

  }
});

// app.post("/generate-employee-invite", async (req, res) => {

//   try {

//     const {
//       telegram_group_id,
//       employee_code
//     } = req.body;

//     const groupEntity = await client.getEntity(
//       Number(telegram_group_id)
//     );

//     const invite = await client.invoke(
//       new Api.messages.ExportChatInvite({
//         peer: groupEntity
//       })
//     );

//     const inviteLink = invite.link;

//     const inviteHash =
//       inviteLink.includes("+")
//         ? inviteLink.split("+")[1]
//         : inviteLink;

//     res.json({
//       success: true,
//       data: {
//         employee_code,
//         telegram_group_id,
//         invite_link: inviteLink,
//         invite_hash: inviteHash
//       }
//     });

//   } catch (err) {

//     console.log(err);

//     res.status(500).json({
//       success: false,
//       message: err.message
//     });

//   }

// });

app.listen(5001, () => {
  console.log("MTProto Service Running on 5001");
});

process.stdin.resume();