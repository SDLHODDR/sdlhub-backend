const axios = require("axios");
const bot = require(
  "./telegramClient"
);
const mapUserGroupUrl = process.env.TELEGRAM_MAP_USER_GROUP_URL;

bot.getMe().then((me) => {
  console.log("BOT INFO:", me);
});

bot.on("polling_error", (error) => {
  console.log("POLLING ERROR");
  console.log(error);
  if (error.response) {
    console.error("Response:");
    console.error(error.response.body);
  }
});


bot.on("message", (msg) => {
  console.log("FULL MESSAGE");
  console.log(JSON.stringify(msg, null, 2));
});

console.log("Bot Started...");



bot.onText(/\/start(?:\s+(.+))?/, async (msg, match) => {
  const startParam = match?.[1];

  if (!startParam) {
    return bot.sendMessage(
      msg.chat.id,
      "Invalid link."
    );
  }

  const chatId = msg.chat.id;
  //const empCode = match[1];
  const [empCode, groupId] = startParam.split("_");

  console.log("Message:", msg);
  console.log("Chat ID:", chatId);
  console.log("Emp Code:", empCode);
  console.log("Group Code:", groupId);
  console.log( "START RECEIVED", empCode, chatId, groupId );
  


  if (!empCode) {
    return bot.sendMessage(
      chatId,
      "Invalid invite link."
    );
  }

  try {
    const response = await axios.post(
      mapUserGroupUrl,
      {
        emp_code: empCode,
        group_id: groupId,
        telegram_chat_id: chatId,
      }
    );

    const groups = response.data?.data?.data?.groups || [];
    let message = "Welcome.\n\nYour Group Links:\n\n";

    groups.forEach((group, index) => {
      message += `${index + 1}. ${group.GROUP_NAME}\n`;
      group.INVITE_LINK.forEach((link) => {
        message += `${link.INVITE_LINK}\n`;
      });
      message += "\n";
    });

    bot.sendMessage(chatId, message);
  } catch (error) {
    console.error(error);

    bot.sendMessage(
      chatId,
      "Error fetching group links."
    );
  }
});